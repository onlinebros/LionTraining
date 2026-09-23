<?php

namespace Tests\Feature\Opportunities;

use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One back office, several front doors.
 *
 * A front door that sells the product line asks for nothing, and must not put
 * a partner in front of a card form for something they did not come for. A
 * front door that sells the membership asks for a card, when the membership is
 * on sale — see TrainingProgramClosedTest for when it is not.
 *
 * The two failure modes this pins down are both quiet ones:
 *
 *   - A PlasmaGuard partner bounced to card capture. The subscription gate
 *     redirects anyone without an entitling subscription, and these partners
 *     have none by design, so forgetting the exemption is a dead end that looks
 *     exactly like ordinary behaviour.
 *   - A PlasmaGuard partner let into the training library. Skipping the card is
 *     not the same as having bought the program, and the gate that used to mean
 *     "has paid" is the one being relaxed.
 */
class OpportunitySignupTest extends TestCase
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
    }

    private function sponsor(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::FREE_MEMBER)->id]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name'                  => 'New Partner',
            'email'                 => 'new@example.com',
            'password'              => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
        ];
    }

    // ── The registry ─────────────────────────────────────────────────────────

    public function test_the_default_business_line_requires_the_membership(): void
    {
        // The whole exemption rests on this being true by default: an account
        // with no recorded business line must behave exactly as it always has.
        $this->assertTrue(Opportunity::default()->requiresMembership());
        $this->assertTrue(Opportunity::default()->allows('training'));
    }

    public function test_the_plasmaguard_line_takes_no_card_and_has_no_training(): void
    {
        $line = Opportunity::get('plasmaguard');

        $this->assertFalse($line->requiresMembership());
        $this->assertFalse($line->allows('training'));
        $this->assertTrue($line->allows('product-sales'));
    }

    public function test_an_unknown_key_falls_back_to_the_default_rather_than_throwing(): void
    {
        // These keys arrive from a public query string. A typo has to land
        // somebody on the ordinary sign-up, never on an error page.
        $this->assertSame(Opportunity::defaultKey(), Opportunity::get('nope')->key);
        $this->assertNull(Opportunity::sanitise('nope'));
    }

    // ── Coming through the PlasmaGuard door ──────────────────────────────────

    public function test_the_join_page_says_no_card_is_needed(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code).'?o=plasmaguard&s=q3.life')
            ->assertOk()
            ->assertSee('PlasmaGuard Products')
            ->assertSee('No card needed');
    }

    public function test_signing_up_records_the_business_line_and_the_site(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code).'?o=plasmaguard&s=q3.life');
        $this->post(route('join.post', $sponsor->referral_code), $this->payload());

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('plasmaguard', $user->opportunityKey());
        $this->assertSame('q3.life', $user->entry_site);
        $this->assertDatabaseHas('user_opportunities', [
            'user_id'     => $user->id,
            'opportunity' => 'plasmaguard',
            'source'      => UserOpportunity::SOURCE_SIGNUP,
        ]);
    }

    public function test_they_land_on_the_dashboard_rather_than_card_capture(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code).'?o=plasmaguard');

        $this->post(route('join.post', $sponsor->referral_code), $this->payload())
            ->assertRedirect(route('member.dashboard'));
    }

    public function test_an_ordinary_signup_still_goes_to_card_capture(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code));

        $this->post(route('join.post', $sponsor->referral_code), $this->payload())
            ->assertRedirect(route('member.billing.start'));
    }

    public function test_a_stray_unknown_key_does_not_exempt_anybody(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code).'?o=free-money-please');

        $this->post(route('join.post', $sponsor->referral_code), $this->payload())
            ->assertRedirect(route('member.billing.start'));

        $this->assertSame(Opportunity::defaultKey(), User::where('email', 'new@example.com')->firstOrFail()->opportunityKey());
    }

    // ── What they can reach afterwards ───────────────────────────────────────

    private function plasmaguardPartner(): User
    {
        $user = User::factory()->create([
            'role_id'             => Role::findByName(Role::FREE_MEMBER)->id,
            'primary_opportunity' => 'plasmaguard',
        ]);
        $user->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_SIGNUP, primary: true);

        return $user->refresh();
    }

    public function test_the_subscription_gate_lets_them_through_with_no_card(): void
    {
        $user = $this->plasmaguardPartner();

        $this->assertFalse($user->hasActiveMembership());

        $this->actingAs($user)
            ->get(route('member.dashboard'))
            ->assertOk();
    }

    public function test_a_member_on_the_default_line_with_no_card_is_still_stopped(): void
    {
        $user = User::factory()->create(['role_id' => Role::findByName(Role::FREE_MEMBER)->id]);

        $this->actingAs($user)
            ->get(route('member.dashboard'))
            ->assertRedirect(route('member.billing.start'));
    }

    public function test_the_training_library_does_not_exist_for_them(): void
    {
        // 404 rather than 403, and specifically NOT the billing redirect a
        // member on commission hold gets: they have not deferred a purchase,
        // they joined for something else entirely.
        \App\Support\TrainingAccess::open();

        $this->actingAs($this->plasmaguardPartner())
            ->get(route('member.training'))
            ->assertNotFound();
    }

    public function test_a_partner_shares_their_own_lines_website(): void
    {
        // A B2B partner handed the company site would be sending prospects to a
        // membership they were never offered, and the link would work, so
        // nobody would notice.
        config([
            'registration.site_url' => 'https://q3.life',
            'opportunities.opportunities.plasmaguard.site_url' => 'https://air.example.test',
        ]);

        $ordinary = User::factory()->create([
            'role_id'        => Role::findByName(Role::FREE_MEMBER)->id,
            'billing_exempt' => true,
        ]);
        $partner = $this->plasmaguardPartner();

        $this->actingAs($ordinary)
            ->get(route('member.referrals'))
            ->assertOk()
            ->assertSee("https://q3.life/{$ordinary->referral_code}");

        $this->actingAs($partner)
            ->get(route('member.referrals'))
            ->assertOk()
            ->assertSee("https://air.example.test/{$partner->referral_code}")
            ->assertDontSee("https://q3.life/{$partner->referral_code}");
    }

    public function test_a_partners_referral_link_carries_their_own_line(): void
    {
        // Without the parameter the link still works and still enrols somebody —
        // it just asks them for a card on arrival. A free clean-air partner
        // would have no way of knowing their own link did that.
        $partner = $this->plasmaguardPartner();

        $this->assertStringContainsString('o=plasmaguard', $partner->referralJoinUrl());
        $this->assertStringContainsString('/join/'.$partner->referral_code, $partner->referralJoinUrl());

        $this->actingAs($partner)
            ->get(route('member.referrals'))
            ->assertOk()
            ->assertSee('o=plasmaguard', escape: false);
    }

    public function test_a_partner_who_has_not_bought_the_training_program_is_offered_it(): void
    {
        $this->actingAs($this->plasmaguardPartner())
            ->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Training Program')
            ->assertSee('Optional');
    }

    public function test_a_member_who_has_bought_it_is_not_offered_it_again(): void
    {
        // billing_exempt stands in for "already has the program" without a
        // Stripe round trip; hasActiveMembership() is what both read.
        $user = User::factory()->create([
            'role_id'        => Role::findByName(Role::FREE_MEMBER)->id,
            'billing_exempt' => true,
        ]);

        $this->actingAs($user)
            ->get(route('member.dashboard'))
            ->assertOk()
            ->assertDontSee('Opening soon');
    }

    public function test_buying_the_membership_adds_the_training_line(): void
    {
        $user = $this->plasmaguardPartner();

        $this->assertFalse($user->canSee('training'));

        // What BillingService does once a subscription is open. Asserted
        // against the model rather than through Stripe, because what is being
        // pinned is the consequence, not the charge.
        $user->associateOpportunity(Opportunity::defaultKey(), UserOpportunity::SOURCE_SELF);
        $user->refresh();

        $this->assertTrue($user->canSee('training'));
        $this->assertTrue($user->requiresMembership());
        // They still came in through the PlasmaGuard door, and that stays true.
        $this->assertSame('plasmaguard', $user->opportunityKey());
    }
}
