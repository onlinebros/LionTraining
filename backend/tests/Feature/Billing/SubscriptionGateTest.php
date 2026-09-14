<?php

namespace Tests\Feature\Billing;

use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Prelaunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The subscription gate and the entitlement rules behind it.
 *
 * The redirect-loop test is the important one here: it is the specific way this
 * pattern most often ships broken.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
            [Role::SUPER_ADMIN, 'Super Admin', true, 99],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }

        config(['prelaunch.enabled' => false, 'stripe.grace_days' => 7]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id,
        ]);
    }

    private function subscribe(User $user, string $status, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id'                  => $user->id,
            'provider_subscription_id' => 'sub_' . uniqid(),
            'status'                   => $status,
            'current_period_end'       => now()->addMonth(),
        ]);
    }

    // ── Entitlement ───────────────────────────────────────────────────────────

    public function test_trialing_and_active_entitle_access(): void
    {
        foreach ([Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE] as $status) {
            $user = $this->member();
            $this->subscribe($user, $status);
            $this->assertTrue($user->fresh()->hasActiveMembership(), "{$status} should entitle");
        }
    }

    public function test_canceled_and_incomplete_do_not_entitle(): void
    {
        foreach ([Subscription::STATUS_CANCELED, Subscription::STATUS_INCOMPLETE, Subscription::STATUS_UNPAID] as $status) {
            $user = $this->member();
            $this->subscribe($user, $status);
            $this->assertFalse($user->fresh()->hasActiveMembership(), "{$status} should not entitle");
        }
    }

    public function test_past_due_entitles_only_inside_the_configured_grace_window(): void
    {
        // Inside grace: period ended two days ago, grace is seven.
        $inGrace = $this->member();
        $this->subscribe($inGrace, Subscription::STATUS_PAST_DUE, ['current_period_end' => now()->subDays(2)]);
        $this->assertTrue($inGrace->fresh()->hasActiveMembership());

        // Past grace: period ended ten days ago.
        $expired = $this->member();
        $this->subscribe($expired, Subscription::STATUS_PAST_DUE, ['current_period_end' => now()->subDays(10)]);
        $this->assertFalse($expired->fresh()->hasActiveMembership());
    }

    public function test_past_due_with_no_period_end_fails_closed(): void
    {
        $user = $this->member();
        $this->subscribe($user, Subscription::STATUS_PAST_DUE, ['current_period_end' => null]);

        // An incomplete mirror must not grant indefinite access.
        $this->assertFalse($user->fresh()->hasActiveMembership());
    }

    public function test_admins_and_exempt_accounts_bypass_the_gate(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id]);
        $this->assertTrue($admin->hasActiveMembership());

        $comped = $this->member(['billing_exempt' => true]);
        $this->assertTrue($comped->fresh()->hasActiveMembership());
    }

    // ── The redirect loop ─────────────────────────────────────────────────────

    public function test_billing_routes_are_not_behind_the_subscription_gate(): void
    {
        // The failure this guards against: the gate redirects an unsubscribed
        // partner to the billing screen, the billing screen is also gated, and
        // the partner bounces forever without ever reaching the page that would
        // fix their state.
        $user = $this->member();

        foreach (['member.billing.start', 'member.billing.index'] as $route) {
            $response = $this->actingAs($user)->get(route($route));

            $this->assertNotEquals(
                302,
                $response->getStatusCode(),
                "{$route} redirected — it must be reachable by an unsubscribed partner.",
            );
        }
    }

    public function test_prelaunch_bypasses_the_membership_gate(): void
    {
        config(['prelaunch.enabled' => true, 'prelaunch.bypass_membership' => true]);

        // During pre-launch nothing is billed, so the gate must not lock a new
        // enrollee out of the tree they were just placed in.
        $this->assertTrue(Prelaunch::bypassesMembership());

        $user = $this->member();
        $this->actingAs($user)->get(route('member.dashboard'))->assertOk();
    }

    public function test_start_redirects_to_manage_when_already_subscribed(): void
    {
        $user = $this->member();
        $this->subscribe($user, Subscription::STATUS_ACTIVE);

        // Leaving a subscribed partner on a card form invites a second subscription.
        $this->actingAs($user->fresh())
            ->get(route('member.billing.start'))
            ->assertRedirect(route('member.billing.index'));
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_stale_scope_finds_mirrors_that_have_not_been_confirmed(): void
    {
        $user = $this->member();

        $fresh = $this->subscribe($user, Subscription::STATUS_ACTIVE);
        $fresh->update(['last_synced_at' => now()->subHour()]);

        $stale = $this->subscribe($user, Subscription::STATUS_ACTIVE);
        $stale->update(['last_synced_at' => now()->subDays(3)]);

        $never = $this->subscribe($user, Subscription::STATUS_ACTIVE);

        $ids = Subscription::stale()->pluck('id')->all();

        $this->assertContains($stale->id, $ids);
        $this->assertContains($never->id, $ids);
        $this->assertNotContains($fresh->id, $ids);
    }
}
