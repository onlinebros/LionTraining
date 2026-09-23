<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Partner\ActivationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My Team reads members, never the unclaimed positions they sit among.
 *
 * The distinction is the whole cost of the page. A partner company's import
 * puts its entire organisation below one account — iHub landed 1.3 million
 * positions — while the people who have actually claimed one number in the
 * dozens. A tree that reads the subtree and filters it afterwards therefore
 * reads a million rows to draw eighty, and sorting them for the row limit spilt
 * 360MB to disk and took 32.7 seconds on production. Cloudflare hung up on the
 * member before the page arrived.
 *
 * So these pin the population, not the timing: whatever the tree costs, it must
 * scale with claims rather than with imports. A timing assertion would pass on
 * a laptop with a hundred rows in it and tell nobody anything.
 */
class TeamTreeReadsOnlyMembersTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->company = PartnerCompany::create(['slug' => 'acme', 'name' => 'iHub Global']);

        $this->founder = User::factory()->create(['billing_exempt' => true]);
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();
    }

    /** An imported position nobody has claimed. */
    private function spot(string $externalId, User $parent): User
    {
        $spot = new User(['name' => "Spot {$externalId}"]);
        $spot->account_status       = User::ACCOUNT_HOLDING;
        $spot->partner_company_id   = $this->company->id;
        $spot->external_user_id     = $externalId;
        $spot->activation_code_hash = ActivationCode::hash('CODE-' . $externalId);
        $spot->imported_at          = now();
        $spot->is_active            = false;
        $spot->save();

        return app(EnrollmentService::class)->enrollImported($spot, $parent)->refresh();
    }

    private function member(User $parent, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['billing_exempt' => true]);

        return app(EnrollmentService::class)->enroll($user, $parent)->refresh();
    }

    public function test_the_tree_does_not_read_the_unclaimed_positions_around_its_members(): void
    {
        // One claimed partner, sitting under an unclaimed leg head, among fifty
        // positions nobody has claimed. The shape a partly-claimed import has.
        $legHead = $this->spot('LEG', $this->founder);
        $claimed = $this->member($legHead, ['name' => 'Claimed Partner']);

        for ($i = 0; $i < 50; $i++) {
            $this->spot("S-{$i}", $this->founder);
        }

        // Read before the listener goes on, so that asking the question does
        // not itself count as the tree answering it.
        $spotIds = User::query()->where('account_status', User::ACCOUNT_HOLDING)->pluck('id');

        $this->assertCount(51, $spotIds);

        $root     = $this->founder->refresh();
        $hydrated = [];

        User::getEventDispatcher()->listen('eloquent.retrieved: ' . User::class, function (User $model) use (&$hydrated) {
            $hydrated[$model->id] = true;
        });

        $tree = app(GenealogyService::class)->subtree($root);

        // 51 unclaimed positions sit below the founder. None of them is a node
        // on the tree, so none of them should have been read to draw it.
        foreach ($spotIds as $spotId) {
            $this->assertArrayNotHasKey(
                $spotId,
                $hydrated,
                "The tree read unclaimed position {$spotId}. It scales with imports, not claims.",
            );
        }

        // And it still draws the partner who claimed beneath one, compressed on
        // to the founder — the case that makes filtering-after-the-fact wrong.
        $this->assertSame(1, $tree['direct_count']);
        $this->assertSame($claimed->id, $tree['children'][0]['id']);
        $this->assertSame(1, $tree['children'][0]['depth']);
    }

    public function test_the_member_page_renders_over_a_mostly_unclaimed_organisation(): void
    {
        $legHead = $this->spot('LEG', $this->founder);
        $this->member($legHead, ['name' => 'Claimed Partner']);

        for ($i = 0; $i < 20; $i++) {
            $this->spot("S-{$i}", $this->founder);
        }

        $this->actingAs($this->founder->refresh())
            ->get(route('member.network'))
            ->assertOk()
            ->assertSee('Claimed Partner')
            ->assertDontSee('Spot S-1');
    }
}
