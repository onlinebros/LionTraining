<?php

namespace Tests\Feature\Billing;

use App\Models\CommissionPayout;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Stripe\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeWithSubscriptions;
use Tests\TestCase;

/**
 * Admins stopping a partner being charged, from the subscriptions list (CR-0001).
 *
 * The cases pinned are the ones where money could still move: a never-charged
 * partner is ended now with their card gone, a cancelled hold is not charged
 * when commissions reach the threshold, and a paying partner keeps the period
 * they paid for.
 */
class AdminSubscriptionCancelTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeWithSubscriptions $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 12:00:00');

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
            [Role::SUPER_ADMIN, 'Super Admin', true, 9],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }

        config([
            'stripe.secret' => 'sk_test_fake',
            'stripe.key'    => 'pk_test_fake',
            'stripe.subscription.price_id' => 'price_fake',
            'stripe.subscription.amount'   => 4999,
            'stripe.subscription.interval' => 'month',
            'stripe.subscription.commission_threshold' => 200,
            'stripe.safeguards.enforce_card_uniqueness' => true,
            'prelaunch.enabled' => false,
            'prelaunch.ends_at' => null,
        ]);

        $this->stripe = new FakeStripeWithSubscriptions();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id]);
    }

    private function partner(): User
    {
        $user = User::factory()->create(['role_id' => Role::findByName(Role::FREE_MEMBER)->id]);
        $user->provider_customer_id = 'cus_'.$user->id;
        $user->save();

        return $user;
    }

    private function enroll(User $user, string $trigger): Subscription
    {
        $card = $this->stripe->card('pm_'.$user->id, 'fp_'.$user->id, $user->provider_customer_id);

        return app(BillingService::class)->startSubscription($user, $card, $trigger);
    }

    public function test_never_charged_partner_is_cancelled_now_and_their_card_removed(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.subscriptions.cancel', $subscription), ['remove_cards' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('delete', collect($this->stripe->calls)->last(fn ($c) => str_starts_with($c['path'], '/v1/subscriptions/'))['method']);
        $this->assertSame('canceled', $subscription->fresh()->status);
        $this->assertTrue($this->stripe->wasDetached('pm_'.$user->id));
        $this->assertSame(0, $user->paymentMethods()->count());
    }

    public function test_unticking_the_card_box_keeps_the_card(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.subscriptions.cancel', $subscription))
            ->assertSessionHasNoErrors();

        $this->assertSame('canceled', $subscription->fresh()->status);
        $this->assertSame(1, $user->paymentMethods()->count());
    }

    public function test_paying_partner_stops_renewing_and_keeps_their_period(): void
    {
        config(['prelaunch.ends_at' => '2026-09-01 09:00:00']);

        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_LAUNCH);
        $this->assertSame('active', $subscription->status);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.subscriptions.cancel', $subscription), ['remove_cards' => '1'])
            ->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame(1, $user->paymentMethods()->count());

        $this->actingAs($this->admin())
            ->post(route('admin.billing.subscriptions.resume', $subscription))
            ->assertSessionHasNoErrors();

        $this->assertFalse($subscription->fresh()->cancel_at_period_end);
    }

    public function test_a_hold_set_to_cancel_is_not_charged_when_commissions_reach_the_threshold(): void
    {
        config(['prelaunch.ends_at' => '2026-09-01 09:00:00']);

        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        // The partner cancelled from their own Billing page.
        app(BillingService::class)->cancelAtPeriodEnd($subscription);

        $payout = CommissionPayout::create(['earner_id' => $user->id, 'total_amount' => 250, 'status' => 'approved']);
        app(CommissionService::class)->markPayoutPaid($payout, 'ref_'.$payout->id, 'manual');

        $this->assertSame('trialing', $subscription->fresh()->status);
        $this->assertNotContains('now', array_column(
            $this->stripe->paramsFor('post', "/v1/subscriptions/{$subscription->provider_subscription_id}"),
            'trial_end',
        ));
    }

    public function test_cards_cannot_be_removed_while_a_subscription_is_live(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.subscriptions.remove-cards', $subscription))
            ->assertSessionHasErrors('subscription');

        $this->assertSame(1, $user->paymentMethods()->count());
    }

    public function test_cards_left_after_a_cancel_can_be_removed_later(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.billing.subscriptions.cancel', $subscription));
        $this->actingAs($admin)->get(route('admin.billing.subscriptions'))->assertOk()->assertSee('Remove card');

        $this->actingAs($admin)
            ->post(route('admin.billing.subscriptions.remove-cards', $subscription))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->paymentMethods()->count());
    }

    public function test_the_list_offers_cancel_on_live_subscriptions(): void
    {
        $this->enroll($this->partner(), Subscription::TRIGGER_COMMISSION);

        $this->actingAs($this->admin())->get(route('admin.billing.subscriptions'))
            ->assertOk()->assertSee('Cancel')->assertDontSee('Remove card');
    }
}
