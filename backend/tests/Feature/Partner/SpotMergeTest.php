<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Partner\ActivationCode;
use App\Services\Partner\SpotMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The founder case: one person, two positions, one team.
 *
 * A founder has a Quantum position from before any of this, and an iHub
 * position with an organisation beneath it. Both are theirs. Merging retires
 * the imported position and re-hangs everyone who was beneath it under the
 * account that survives.
 *
 * This is the only operation in the system that rearranges a live genealogy, so
 * these tests are mostly about what it refuses to do.
 */
class SpotMergeTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->company = PartnerCompany::create([
            'slug' => 'ihub', 'name' => 'iHub Global', 'is_active' => true,
        ]);

        $this->founder = User::factory()->create(['billing_exempt' => true, 'name' => 'Founder']);
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();
    }

    private function spot(string $externalId, User $parent, string $code = 'CODE-X'): User
    {
        $spot = new User(['name' => "Spot {$externalId}"]);
        $spot->account_status       = User::ACCOUNT_HOLDING;
        $spot->partner_company_id   = $this->company->id;
        $spot->external_user_id     = $externalId;
        $spot->activation_code_hash = ActivationCode::hash($code);
        $spot->imported_at          = now();
        $spot->is_active            = false;
        $spot->save();

        return app(EnrollmentService::class)->enrollImported($spot, $parent)->refresh();
    }

    private function claimed(User $spot, string $email): User
    {
        $spot->forceFill([
            'account_status' => User::ACCOUNT_ACTIVE,
            'is_active'      => true,
            'claimed_at'     => now(),
            'email'          => $email,
        ])->save();

        return $spot->refresh();
    }

    private function merges(): SpotMergeService
    {
        return app(SpotMergeService::class);
    }

    private function genealogy(): GenealogyService
    {
        return app(GenealogyService::class);
    }

    // ── Moving the team ───────────────────────────────────────────────────────

    public function test_the_whole_downline_moves_under_the_surviving_account(): void
    {
        // A three-deep leg hanging off an imported head, alongside the founder.
        $head   = $this->spot('IHUB-1', $this->founder);
        $middle = $this->spot('IHUB-2', $head);
        $bottom = $this->spot('IHUB-3', $middle);

        $this->claimed($head, 'head@example.com');
        $this->claimed($middle, 'middle@example.com');
        $this->claimed($bottom, 'bottom@example.com');

        $moved = $this->merges()->merge($head->refresh(), $this->founder);

        $this->assertSame(2, $moved);

        // The people below the merged position keep their order and come up one
        // level: middle now hangs off the founder directly.
        $this->assertSame($this->founder->id, $middle->refresh()->placement_parent_id);
        $this->assertSame($middle->id, $bottom->refresh()->placement_parent_id);

        $this->assertSame(
            "{$this->founder->placement_path}.{$middle->id}.{$bottom->id}",
            $bottom->refresh()->placement_path,
        );
    }

    public function test_the_merged_position_leaves_the_structure_but_not_the_records(): void
    {
        $head = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');

        $this->merges()->merge($head, $this->founder);

        $head->refresh();

        $this->assertTrue($head->isMerged());
        $this->assertSame($this->founder->id, $head->merged_into_user_id);
        $this->assertNull($head->placement_path);
        $this->assertNull($head->password);

        // The email is released so the person can use it on the account that
        // survived; the partner's identifier is kept, because iHub will quote
        // it at us for years.
        $this->assertNull($head->email);
        $this->assertSame('IHUB-1', $head->external_user_id);
    }

    public function test_the_survivor_counts_the_moved_team_as_their_own(): void
    {
        $head = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');
        $a    = $this->claimed($this->spot('IHUB-2', $head), 'a@example.com');
        $this->claimed($this->spot('IHUB-3', $a), 'b@example.com');

        $before = $this->genealogy()->teamSize($this->founder);

        $this->merges()->merge($head->refresh(), $this->founder);

        // Three positions were below the founder; one of them was absorbed, so
        // the team is one smaller and the other two are still there.
        $this->assertSame(3, $before);
        $this->assertSame(2, $this->genealogy()->teamSize($this->founder->refresh()));
        $this->assertSame([1 => 1, 2 => 1], $this->genealogy()->teamCountsByLevel($this->founder)->all());
    }

    public function test_a_merged_position_is_gone_from_the_tree_and_the_admin_list(): void
    {
        $head = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');
        $this->merges()->merge($head, $this->founder);

        $tree = $this->genealogy()->subtree($this->founder->refresh());

        $this->assertSame([], $tree['children']);
        $this->assertFalse(User::query()->activated()->whereKey($head->id)->exists());
    }

    public function test_a_merged_position_stops_being_able_to_sponsor_anybody(): void
    {
        $head = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');
        $code = $head->referral_code;

        $this->assertNotNull($code, 'a claimed position should have a referral code to lose');

        $this->merges()->merge($head, $this->founder);

        // The link is still printed in whatever they shared before the merge.
        // It has to be dead: the position is out of the structure, so enrolling
        // somebody beneath it fails deep in the genealogy rather than politely.
        $this->assertNull($head->refresh()->referral_code);
        $this->get(route('join', $code))->assertNotFound();
    }

    public function test_an_unclaimed_position_can_never_be_a_sponsor(): void
    {
        // Really-imported positions have no referral code at all: the committer
        // bulk-inserts, which skips the model event that hands them out, and
        // all 1,304,352 of iHub's came through with it null. This helper builds
        // them through Eloquent, so it gets one — which makes it the stricter
        // test. Relying on a null column to keep a door shut is not the same as
        // locking the door.
        $spot = $this->spot('IHUB-1', $this->founder);
        $spot->forceFill(['referral_code' => 'TESTCODE'])->save();

        $this->get(route('join', 'TESTCODE'))->assertNotFound();
    }

    // ── What it refuses ───────────────────────────────────────────────────────

    public function test_an_unclaimed_position_cannot_be_merged_by_an_administrator(): void
    {
        $spot = $this->spot('IHUB-1', $this->founder);

        // Ownership has to be established by its owner, not asserted by staff.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has not been claimed yet/');

        $this->merges()->merge($spot, $this->founder);
    }

    public function test_merging_into_an_account_inside_the_subtree_is_refused(): void
    {
        $head  = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');
        $below = $this->claimed($this->spot('IHUB-2', $head), 'below@example.com');

        // Every path under the head gets rewritten to start with the
        // survivor's; if the survivor is in there, it becomes its own ancestor.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sits beneath the position being merged/');

        $this->merges()->merge($head->refresh(), $below->refresh());
    }

    public function test_a_position_cannot_be_merged_twice(): void
    {
        $head = $this->claimed($this->spot('IHUB-1', $this->founder), 'head@example.com');
        $this->merges()->merge($head, $this->founder);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been merged/');

        $this->merges()->merge($head->refresh(), $this->founder);
    }

    // ── Through the claim flow ────────────────────────────────────────────────

    public function test_a_signed_in_partner_is_offered_their_existing_account(): void
    {
        $this->spot('IHUB-1', $this->founder, 'CODE-ALPHA');

        $this->actingAs($this->founder)
            ->post(route('partner.claim.verify', 'ihub'), [
                'external_user_id' => 'IHUB-1',
                'activation_code'  => 'CODE-ALPHA',
            ]);

        $this->actingAs($this->founder)
            ->get(route('partner.claim.details', 'ihub'))
            ->assertOk()
            ->assertSee('You already have a Quantum 3 Solution account')
            ->assertSee('Add it to my Founder account');
    }

    public function test_claiming_into_an_existing_account_moves_the_team(): void
    {
        $head   = $this->spot('IHUB-1', $this->founder, 'CODE-ALPHA');
        $below  = $this->spot('IHUB-2', $head);

        $this->actingAs($this->founder)
            ->post(route('partner.claim.verify', 'ihub'), [
                'external_user_id' => 'IHUB-1',
                'activation_code'  => 'CODE-ALPHA',
            ]);

        $this->actingAs($this->founder)
            ->post(route('partner.claim.merge', 'ihub'))
            ->assertRedirect(route('member.network'));

        // Typing the activation code is better proof of ownership than anything
        // an administrator could act on afterwards, so the claim flow is allowed
        // to merge a position that was still unclaimed a moment ago.
        $this->assertTrue($head->refresh()->isMerged());
        $this->assertSame($this->founder->id, $below->refresh()->placement_parent_id);
    }

    public function test_the_merge_offer_is_not_shown_to_somebody_who_is_not_signed_in(): void
    {
        $this->spot('IHUB-1', $this->founder, 'CODE-ALPHA');

        $this->post(route('partner.claim.verify', 'ihub'), [
            'external_user_id' => 'IHUB-1',
            'activation_code'  => 'CODE-ALPHA',
        ]);

        $this->get(route('partner.claim.details', 'ihub'))
            ->assertOk()
            ->assertDontSee('You already have a Quantum 3 Solution account');
    }

    // ── What the partner is told ──────────────────────────────────────────────

    public function test_the_partner_is_told_the_position_was_claimed_and_merged(): void
    {
        $this->company->update([
            'webhook_url'     => 'https://api.ihub.example/hook',
            'webhook_secret'  => 'a-secret-at-least-16-chars',
            'webhook_enabled' => true,
        ]);

        $head = $this->spot('IHUB-1', $this->founder, 'CODE-ALPHA');
        $this->spot('IHUB-2', $head);

        Http::fake();

        $this->actingAs($this->founder)
            ->post(route('partner.claim.verify', 'ihub'), [
                'external_user_id' => 'IHUB-1', 'activation_code' => 'CODE-ALPHA',
            ]);
        $this->actingAs($this->founder)->post(route('partner.claim.merge', 'ihub'));

        $payload = \App\Models\PartnerWebhookDelivery::firstOrFail()->payload;

        $this->assertSame('IHUB-1', $payload['external_user_id']);
        $this->assertNotNull($payload['claimed_at']);

        // Without this the partner sees a downline apparently reparented for no
        // reason when they reconcile their tree against ours.
        $this->assertSame($this->founder->id, $payload['merged_into']['quantum_user_id']);

        // Built before anything moved, so the position it describes is the one
        // that was claimed rather than the hole it left. After the merge this
        // position has no path at all, so a payload built afterwards would
        // report nothing below it.
        $this->assertSame(1, $payload['position']['unclaimed_below']);
        // And no claimed members below it yet — the one position under it is
        // still an unclaimed spot, which is not a member.
        $this->assertSame(0, $payload['position']['team_size']);
    }
}
