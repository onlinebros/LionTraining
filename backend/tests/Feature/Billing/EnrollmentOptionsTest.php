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
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * The two enrollment options on the card-capture page.
 *
 * "Recover my genius now" is charged the day the training program opens (or at
 * sign-up once it has). "Wait for my commissions" is held until paid
 * commissions add up to the threshold, with training locked meanwhile.
 */
class EnrollmentOptionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeWithSubscriptions $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-16 12:00:00');

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
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

    private function lastSubscriptionCreate(): array
    {
        return collect($this->stripe->paramsFor('post', '/v1/subscriptions'))->last();
    }

    private function paidPayout(User $user, float $amount, array $extra = []): CommissionPayout
    {
        $payout = CommissionPayout::create($extra + [
            'earner_id'    => $user->id,
            'total_amount' => $amount,
            'status'       => 'approved',
        ]);

        app(CommissionService::class)->markPayoutPaid($payout, 'ref_'.$payout->id, 'manual');

        return $payout;
    }

    // ── Pay now ───────────────────────────────────────────────────────────────

    public function test_pay_now_with_no_date_parks_until_the_training_program_is_ready(): void
    {
        $subscription = $this->enroll($this->partner(), Subscription::TRIGGER_LAUNCH);

        $this->assertSame(Subscription::TRIGGER_LAUNCH, $subscription->billing_trigger);
        $this->assertTrue($subscription->is_prelaunch_trial);
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame('launch', $this->lastSubscriptionCreate()['metadata']['billing_trigger']);
    }

    public function test_pay_now_is_first_charged_on_launch_day_with_no_free_trial(): void
    {
        config(['prelaunch.ends_at' => '2026-10-01 09:00:00']);

        $subscription = $this->enroll($this->partner(), Subscription::TRIGGER_LAUNCH);

        $this->assertSame(Carbon::parse('2026-10-01 09:00:00')->getTimestamp(), (int) $this->lastSubscriptionCreate()['trial_end']);
        $this->assertTrue($subscription->trial_ends_at->equalTo(Carbon::parse('2026-10-01 09:00:00')));
    }

    public function test_pay_now_after_launch_charges_at_sign_up(): void
    {
        config(['prelaunch.ends_at' => '2026-09-01 09:00:00']);

        $subscription = $this->enroll($this->partner(), Subscription::TRIGGER_LAUNCH);
        $params = $this->lastSubscriptionCreate();

        $this->assertArrayNotHasKey('trial_end', $params);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
        $this->assertSame('active', $subscription->status);
    }

    public function test_apply_prelaunch_end_moves_pay_now_to_launch_day_and_leaves_holds_alone(): void
    {
        $now  = $this->enroll($this->partner(), Subscription::TRIGGER_LAUNCH);
        $hold = $this->enroll($this->partner(), Subscription::TRIGGER_COMMISSION);
        $holdEnd = $hold->trial_ends_at;

        config(['prelaunch.ends_at' => '2026-10-01 09:00:00']);

        $this->artisan('billing:apply-prelaunch-end')->assertSuccessful();

        $this->assertTrue($now->fresh()->trial_ends_at->equalTo(Carbon::parse('2026-10-01 09:00:00')));
        $this->assertFalse($now->fresh()->is_prelaunch_trial);
        $this->assertTrue($hold->fresh()->trial_ends_at->equalTo($holdEnd));
    }

    // ── Wait for commissions ──────────────────────────────────────────────────

    public function test_commission_option_is_held_far_out_and_not_on_the_launch_schedule(): void
    {
        config(['prelaunch.ends_at' => '2026-10-01 09:00:00']);

        $subscription = $this->enroll($this->partner(), Subscription::TRIGGER_COMMISSION);

        $this->assertTrue($subscription->isCommissionHold());
        $this->assertFalse($subscription->is_prelaunch_trial);
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(700)));
    }

    public function test_confirm_requires_an_enrollment_option(): void
    {
        $user = $this->partner();

        $this->actingAs($user)
            ->post(route('member.billing.confirm'), ['payment_method' => 'pm_x'])
            ->assertSessionHasErrors('enrollment');

        $this->assertSame(0, $user->subscriptions()->count());
    }

    public function test_confirm_opens_the_chosen_option(): void
    {
        $user = $this->partner();
        $card = $this->stripe->card('pm_form', 'fp_form', $user->provider_customer_id);

        $this->actingAs($user)
            ->post(route('member.billing.confirm'), ['payment_method' => $card, 'enrollment' => 'commission'])
            ->assertRedirect(route('member.billing.index'));

        $this->assertTrue($user->fresh()->isOnCommissionHold());
    }

    public function test_training_is_locked_while_waiting_on_commissions(): void
    {
        $held = $this->partner();
        $this->enroll($held, Subscription::TRIGGER_COMMISSION);

        $this->actingAs($held)->get(route('member.training'))
            ->assertRedirect(route('member.billing.index'));

        $payer = $this->partner();
        $this->enroll($payer, Subscription::TRIGGER_LAUNCH);

        $response = $this->actingAs($payer)->get(route('member.training'));
        $this->assertNotSame(route('member.billing.index'), $response->headers->get('Location'));
    }

    public function test_billing_starts_once_paid_commissions_add_up_to_the_threshold(): void
    {
        config(['prelaunch.ends_at' => '2026-09-01 09:00:00']);

        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->paidPayout($user, 120);
        $this->assertTrue($subscription->fresh()->isCommissionHold());

        $this->paidPayout($user, 80);

        $subscription->refresh();
        $this->assertFalse($subscription->isCommissionHold());
        $this->assertSame('active', $subscription->status);
        $this->assertSame(Subscription::TRIGGER_LAUNCH, $subscription->billing_trigger);
        $this->assertNotNull($subscription->billing_trigger_met_at);
        $this->assertFalse($user->fresh()->isOnCommissionHold());
    }

    public function test_reaching_the_threshold_before_launch_waits_for_launch_day(): void
    {
        config(['prelaunch.ends_at' => '2026-10-01 09:00:00']);

        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->paidPayout($user, 250);

        $subscription->refresh();
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame(Subscription::TRIGGER_LAUNCH, $subscription->billing_trigger);
        $this->assertTrue($subscription->trial_ends_at->equalTo(Carbon::parse('2026-10-01 09:00:00')));
    }

    public function test_a_reversed_transfer_does_not_count_toward_the_threshold(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $payout = CommissionPayout::create([
            'earner_id' => $user->id, 'total_amount' => 300, 'status' => 'paid', 'transfer_status' => 'reversed',
        ]);
        $this->paidPayout($user, 50);

        $this->assertTrue($subscription->fresh()->isCommissionHold());
        $this->assertNotNull($payout->id);
    }

    public function test_partner_can_choose_to_start_now(): void
    {
        config(['prelaunch.ends_at' => '2026-09-01 09:00:00']);

        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        $this->actingAs($user)->post(route('member.billing.start-now'))->assertSessionHasNoErrors();

        $this->assertSame('now', collect($this->stripe->paramsFor('post', "/v1/subscriptions/{$subscription->provider_subscription_id}"))->last()['trial_end']);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_daily_sweep_renews_holds_near_stripes_limit(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        Carbon::setTestNow(now()->addDays(650));

        $this->artisan('billing:commission-holds')->assertSuccessful();

        $this->assertTrue($subscription->fresh()->trial_ends_at->equalTo(now()->addDays(700)));
        $this->assertTrue($subscription->fresh()->isCommissionHold());
    }

    public function test_webhook_sync_keeps_the_option_from_stripe_metadata(): void
    {
        $user = $this->partner();
        $subscription = $this->enroll($user, Subscription::TRIGGER_COMMISSION);

        app(BillingService::class)->refresh($subscription);

        $this->assertSame(Subscription::TRIGGER_COMMISSION, $subscription->fresh()->billing_trigger);
    }
}

/** FakeStripe plus an in-memory subscriptions endpoint. */
class FakeStripeWithSubscriptions extends FakeStripe
{
    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path   = (string) parse_url($absUrl, PHP_URL_PATH);
        $params = is_array($params) ? $params : [];

        if ($method === 'post' && $path === '/v1/subscriptions') {
            $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];
            $id = 'sub_fake'.(count($this->subscriptions) + 1);
            $trialEnd = isset($params['trial_end']) ? (int) $params['trial_end'] : null;

            $this->subscriptions[$id] = [
                'id'        => $id,
                'object'    => 'subscription',
                'status'    => $trialEnd ? 'trialing' : 'active',
                'trial_end' => $trialEnd,
                'metadata'  => $params['metadata'] ?? [],
                'cancel_at_period_end' => false,
                'items'     => ['object' => 'list', 'data' => [[
                    'id' => 'si_'.$id, 'object' => 'subscription_item',
                    'price' => ['id' => 'price_fake', 'object' => 'price', 'unit_amount' => 4999, 'currency' => 'usd'],
                ]]],
            ];

            return [json_encode($this->subscriptions[$id]), 200, []];
        }

        if (preg_match('#^/v1/subscriptions/([^/]+)$#', $path, $m) && isset($this->subscriptions[$m[1]])) {
            $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];
            $sub = &$this->subscriptions[$m[1]];

            if ($method === 'post') {
                if (($params['trial_end'] ?? null) === 'now') {
                    $sub['trial_end'] = now()->getTimestamp();
                    $sub['status'] = 'active';
                } elseif (isset($params['trial_end'])) {
                    $sub['trial_end'] = (int) $params['trial_end'];
                }

                $sub['metadata'] = array_merge($sub['metadata'], $params['metadata'] ?? []);
            }

            return [json_encode($sub), 200, []];
        }

        return parent::request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
    }
}
