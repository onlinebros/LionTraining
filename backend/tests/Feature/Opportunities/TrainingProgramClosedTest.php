<?php

namespace Tests\Feature\Opportunities;

use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The state the application actually ships in: the training program is not on
 * sale, so nobody is asked for a card anywhere.
 *
 * `phpunit.xml` runs the rest of the suite with enrollment OPEN, because that is
 * the state the subscription gate and card capture exist for. This class closes
 * it, which is what `MEMBERSHIP_ENROLLMENT_OPEN=false` means in production.
 *
 * The failure being pinned is a card form that is merely hidden rather than
 * shut. A page nobody links to is still a page somebody has bookmarked, and the
 * endpoints behind it reach the card network.
 */
class TrainingProgramClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }

        config(['opportunities.opportunities.q3-training.membership.enrollment_open' => false]);
    }

    private function member(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::FREE_MEMBER)->id]);
    }

    public function test_the_membership_line_stops_requiring_a_card_while_it_is_closed(): void
    {
        $line = Opportunity::membership();

        $this->assertFalse($line->enrollmentOpen());
        $this->assertFalse($line->requiresMembership());
        // The commercial fact is unchanged — it is a paid product, just not on
        // sale. Losing that distinction would make "closed" indistinguishable
        // from "free", and they need very different copy.
        $this->assertTrue($line->requiresMembershipWhenOpen());
    }

    public function test_a_member_with_no_card_reaches_the_dashboard(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.dashboard'))
            ->assertOk();
    }

    public function test_card_capture_redirects_to_the_training_program_section(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.billing.start'))
            ->assertRedirect(route('member.training-program'));
    }

    public function test_the_setup_intent_endpoint_refuses(): void
    {
        // Reached directly, or from a page left open across the switch being
        // thrown. This is the call that would touch the card network.
        $this->actingAs($this->member())
            ->postJson(route('member.billing.setup-intent'))
            ->assertStatus(422);
    }

    public function test_confirming_a_subscription_refuses_and_creates_nothing(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->post(route('member.billing.confirm'), [
                'payment_method' => 'pm_card_visa',
                'enrollment'     => 'launch',
            ])
            ->assertRedirect(route('member.training-program'));

        $this->assertSame(0, $user->subscriptions()->count());
    }

    public function test_signing_up_lands_on_the_dashboard_not_card_capture(): void
    {
        $sponsor = $this->member();

        $this->get(route('join', $sponsor->referral_code));

        $this->post(route('join.post', $sponsor->referral_code), [
            'name'                  => 'New Partner',
            'email'                 => 'new@example.test',
            'password'              => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
        ])->assertRedirect(route('member.dashboard'));
    }

    // ── The section ──────────────────────────────────────────────────────────

    public function test_the_section_says_it_is_not_open_and_offers_the_list(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.training-program'))
            ->assertOk()
            ->assertSee('not open yet')
            ->assertSee('Tell me when it opens');
    }

    public function test_a_partner_can_join_and_leave_the_list(): void
    {
        $user = $this->member();

        $this->actingAs($user)->post(route('member.training-program.interest'))->assertRedirect();
        $this->assertNotNull($user->refresh()->training_interest_at);
        $asked = $user->training_interest_at;

        // Asking twice keeps the first timestamp, so the list stays ordered by
        // when somebody actually asked.
        $this->actingAs($user)->post(route('member.training-program.interest'));
        $this->assertTrue($asked->equalTo($user->refresh()->training_interest_at));

        $this->actingAs($user)->post(route('member.training-program.interest'), ['remove' => 1]);
        $this->assertNull($user->refresh()->training_interest_at);
    }

    public function test_the_section_becomes_an_offer_when_it_opens(): void
    {
        config(['opportunities.opportunities.q3-training.membership.enrollment_open' => true]);

        $user = $this->member();
        // On the product line, so the subscription gate does not bounce them
        // off the page before it renders.
        $user->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_SIGNUP, primary: true);

        $this->actingAs($user->refresh())
            ->get(route('member.training-program'))
            ->assertOk()
            ->assertSee('Join the training program')
            ->assertDontSee('Tell me when it opens');
    }

    public function test_a_member_who_already_has_it_is_pointed_at_billing(): void
    {
        // Being on the membership line is not the same as having bought it, and
        // every pre-existing account is on that line. Only somebody with an
        // actual entitlement is told they already have it.
        $onTheLineButUnpaid = $this->member();
        $this->assertSame(Opportunity::defaultKey(), $onTheLineButUnpaid->opportunityKey());

        $this->actingAs($onTheLineButUnpaid)
            ->get(route('member.training-program'))
            ->assertOk()
            ->assertSee('not open yet')
            ->assertDontSee('You have this');

        $paid = User::factory()->create([
            'role_id'        => Role::findByName(Role::FREE_MEMBER)->id,
            'billing_exempt' => true,
        ]);

        $this->actingAs($paid)
            ->get(route('member.training-program'))
            ->assertOk()
            ->assertSee('You have this');
    }
}
