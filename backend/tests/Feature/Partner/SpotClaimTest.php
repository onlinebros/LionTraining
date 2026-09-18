<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\User;
use App\Services\Partner\ActivationCode;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Partner\SpotClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The co-branded door, and what it does and does not let through.
 *
 * The threat model is in SpotClaimService: the user ids are public, so the
 * activation code is the only thing protecting a position and everything below
 * it. Most of these tests are about guessing.
 */
class SpotClaimTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;
    private User $spot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->company = PartnerCompany::create([
            'slug'      => 'acme',
            'name'      => 'Acme Group',
            'is_active' => true,
        ]);

        $this->founder = User::factory()->create();
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();

        $this->spot = $this->makeSpot('A-1', 'CODE-ALPHA', $this->founder);
    }

    /**
     * The first validation message on a response.
     *
     * Read out of the session rather than off the response because these are
     * redirects, and read eagerly because the next request in the same test
     * replaces the flash.
     */
    private function firstError(): string
    {
        $messages = (array) data_get(session()->get('errors'), 'default.messages', []);

        return (string) (\Illuminate\Support\Arr::flatten($messages)[0] ?? '');
    }

    private function makeSpot(string $externalId, string $code, User $parent): User
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

    // ── The door ──────────────────────────────────────────────────────────────

    public function test_the_claim_page_is_branded_for_the_company(): void
    {
        $this->get(route('partner.claim', 'acme'))
            ->assertOk()
            ->assertSee('Acme Group')
            ->assertSee('Activation Code');
    }

    public function test_a_company_that_is_not_live_has_no_claim_page(): void
    {
        $this->company->update(['is_active' => false]);

        $this->get(route('partner.claim', 'acme'))->assertNotFound();
    }

    public function test_the_right_id_and_code_open_the_details_form(): void
    {
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => 'CODE-ALPHA',
        ])->assertRedirect(route('partner.claim.details', 'acme'));

        $this->get(route('partner.claim.details', 'acme'))
            ->assertOk()
            ->assertSee('A-1');
    }

    public function test_a_code_is_accepted_however_it_was_typed(): void
    {
        // Read off paper, typed by hand, often with the caps lock off.
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => ' code-alpha ',
        ])->assertRedirect(route('partner.claim.details', 'acme'));
    }

    // ── Deep links ────────────────────────────────────────────────────────────

    public function test_a_link_carrying_both_details_fills_the_form_in(): void
    {
        // The partner's own system links their members straight here, so they
        // arrive at an answered form rather than copying a code off a letter.
        $this->get(route('partner.claim', 'acme') . '?uid=A-1&code=CODE-ALPHA')
            ->assertRedirect(route('partner.claim', 'acme'));

        $this->get(route('partner.claim', 'acme'))
            ->assertOk()
            ->assertSee('value="A-1"', false)
            ->assertSee('value="CODE-ALPHA"', false)
            ->assertSee('We filled these in from your');
    }

    public function test_the_code_does_not_stay_in_the_address_bar(): void
    {
        // The redirect is the point: after it, the credential is not in
        // anything the member screenshots, bookmarks or forwards, and not in
        // the Referer we hand to every asset on the page.
        $response = $this->get(route('partner.claim', 'acme') . '?uid=A-1&code=CODE-ALPHA');

        $this->assertSame(route('partner.claim', 'acme'), $response->headers->get('Location'));
        $this->assertStringNotContainsString('CODE-ALPHA', (string) $response->headers->get('Location'));

        $this->get(route('partner.claim', 'acme'))
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Their code generates the link, not ours. Listing spellings by hand loses
     * that bet eventually — it lost it on iHub's `activate_code`, which filled
     * nothing and reported no error — so names are normalised before matching.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('linkSpellings')]
    public function test_the_partner_can_spell_the_parameters_how_they_like(string $query): void
    {
        $this->get(route('partner.claim', 'acme') . '?' . $query);

        $this->get(route('partner.claim', 'acme'))
            ->assertSee('value="A-1"', false)
            ->assertSee('value="CODE-ALPHA"', false);
    }

    /** @return array<string, array{string}> */
    public static function linkSpellings(): array
    {
        return [
            'ours'            => ['uid=A-1&code=CODE-ALPHA'],
            'iHub'            => ['uid=A-1&activate_code=CODE-ALPHA'],
            'snake case'      => ['user_id=A-1&activation_code=CODE-ALPHA'],
            'camel case'      => ['userId=A-1&activationCode=CODE-ALPHA'],
            'hyphens'         => ['user-id=A-1&activate-code=CODE-ALPHA'],
            'shouting'        => ['USERID=A-1&ACTIVATE_CODE=CODE-ALPHA'],
            'their own words' => ['memberId=A-1&accessCode=CODE-ALPHA'],
        ];
    }

    public function test_a_parameter_we_do_not_recognise_is_simply_ignored(): void
    {
        $this->get(route('partner.claim', 'acme') . '?uid=A-1&utm_source=mailer&ref=abc');

        // A tracking parameter on the partner's link must not be mistaken for a
        // credential, and must not stop the ones we do understand working.
        $this->get(route('partner.claim', 'acme'))
            ->assertOk()
            ->assertSee('value="A-1"', false);
    }

    public function test_a_link_never_claims_anything_on_its_own(): void
    {
        // A GET must not spend an attempt or take a position. Mail scanners and
        // link prefetchers follow these before the member ever clicks.
        $this->get(route('partner.claim', 'acme') . '?uid=A-1&code=CODE-ALPHA');

        $this->assertTrue($this->spot->refresh()->isHolding());
        $this->assertSame(0, $this->spot->claim_attempts);
        $this->assertGuest();
    }

    public function test_the_prefilled_code_is_dropped_once_it_has_been_used(): void
    {
        $this->get(route('partner.claim', 'acme') . '?uid=A-1&code=CODE-ALPHA');

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => 'CODE-ALPHA',
        ])->assertRedirect(route('partner.claim.details', 'acme'));

        // Holding a live credential in the session past the point it is useful
        // buys nothing.
        $this->assertNull(session('partner_prefill.' . $this->company->id));
    }

    public function test_a_wrong_code_and_an_unknown_id_give_the_same_answer(): void
    {
        // Captured as we go: the session holds one flash at a time.
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => 'NOT-THE-CODE',
        ]);
        $wrongCode = $this->firstError();

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-99999',
            'activation_code'  => 'NOT-THE-CODE',
        ]);
        $unknownId = $this->firstError();

        // Telling the two apart lets somebody sift the real ids out of a guessed
        // list without ever spending a guess on a code.
        $this->assertNotSame('', $wrongCode);
        $this->assertSame($wrongCode, $unknownId);
    }

    public function test_the_details_form_is_unreachable_without_verifying(): void
    {
        $this->get(route('partner.claim.details', 'acme'))
            ->assertRedirect(route('partner.claim', 'acme'));
    }

    public function test_verifying_at_one_company_is_not_a_pass_at_another(): void
    {
        $other = PartnerCompany::create(['slug' => 'other', 'name' => 'Other Co', 'is_active' => true]);

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => 'CODE-ALPHA',
        ]);

        $this->get(route('partner.claim.details', $other->slug))
            ->assertRedirect(route('partner.claim', $other->slug));
    }

    // ── Guessing ──────────────────────────────────────────────────────────────

    public function test_a_spot_locks_itself_after_repeated_wrong_codes(): void
    {
        for ($i = 0; $i < SpotClaimService::MAX_ATTEMPTS; $i++) {
            $this->post(route('partner.claim.verify', 'acme'), [
                'external_user_id' => 'A-1',
                'activation_code'  => "GUESS-{$i}",
            ]);
        }

        // The ceiling is on the spot, not the visitor — a hundred IPs grinding
        // one position is the attack that matters, and route throttling does
        // nothing about it.
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1',
            'activation_code'  => 'CODE-ALPHA',
        ]);

        $this->assertStringContainsString('Too many incorrect codes', $this->firstError());

        $this->assertTrue($this->spot->refresh()->claim_locked_until->isFuture());
    }

    public function test_a_correct_code_clears_earlier_fumbles(): void
    {
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'WRONG-1',
        ]);
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);

        $this->assertSame(0, $this->spot->refresh()->claim_attempts);
    }

    // ── Claiming ──────────────────────────────────────────────────────────────

    public function test_claiming_fills_in_the_position_without_moving_it(): void
    {
        $pathBefore   = $this->spot->placement_path;
        $parentBefore = $this->spot->placement_parent_id;

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);

        $this->post(route('partner.claim.store', 'acme'), [
            'name'                  => 'Dana Whitfield',
            'email'                 => 'dana@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'phone'                 => '555-0142',
            'terms'                 => '1',
        ])->assertRedirect(route('member.billing.start'));

        $claimed = $this->spot->refresh();

        $this->assertSame('Dana Whitfield', $claimed->name);
        $this->assertSame('dana@example.com', $claimed->email);
        $this->assertTrue($claimed->isActivated());
        $this->assertTrue((bool) $claimed->is_active);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertTrue(Hash::check('password123', $claimed->password));

        // The position was decided at import and does not move on claim.
        $this->assertSame($pathBefore, $claimed->placement_path);
        $this->assertSame($parentBefore, $claimed->placement_parent_id);

        $this->assertAuthenticatedAs($claimed);
    }

    public function test_claiming_moves_the_company_counter(): void
    {
        $this->company->forceFill(['total_spots' => 1, 'unclaimed_spots' => 1])->save();

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);
        $this->post(route('partner.claim.store', 'acme'), [
            'name' => 'Dana', 'email' => 'dana@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $this->assertSame(
            ['total' => 1, 'claimed' => 1, 'unclaimed' => 0],
            $this->company->refresh()->spotCounts(),
        );
    }

    public function test_the_code_cannot_be_replayed_once_the_position_has_an_owner(): void
    {
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);
        $this->post(route('partner.claim.store', 'acme'), [
            'name' => 'Dana', 'email' => 'dana@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $this->post('/logout');

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);

        $this->assertStringContainsString('already been claimed', $this->firstError());

        $this->assertNull($this->spot->refresh()->activation_code_hash);
    }

    public function test_the_claimer_cannot_take_an_email_that_is_already_in_use(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);

        $this->post(route('partner.claim.store', 'acme'), [
            'name' => 'Dana', 'email' => 'taken@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertTrue($this->spot->refresh()->isHolding());
    }

    public function test_claiming_leaves_the_downline_where_it_was(): void
    {
        $below = $this->makeSpot('A-2', 'CODE-BETA', $this->spot);

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ]);
        $this->post(route('partner.claim.store', 'acme'), [
            'name' => 'Dana', 'email' => 'dana@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $this->assertSame($this->spot->id, $below->refresh()->placement_parent_id);

        // And the newly activated partner now sees their own team.
        $this->assertSame(0, app(GenealogyService::class)->teamSize($this->spot->refresh()));
        $this->assertSame(1, app(GenealogyService::class)->teamSize($this->spot, includeHolding: true));
    }

    // ── Support ───────────────────────────────────────────────────────────────

    public function test_a_reissued_code_replaces_the_old_one(): void
    {
        $new = app(SpotClaimService::class)->reissueCode($this->spot);

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => 'CODE-ALPHA',
        ])->assertSessionHasErrors();

        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => 'A-1', 'activation_code' => $new,
        ])->assertRedirect(route('partner.claim.details', 'acme'));
    }
}
