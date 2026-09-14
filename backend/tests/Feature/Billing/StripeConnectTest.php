<?php

namespace Tests\Feature\Billing;

use App\Exceptions\BillingException;
use App\Models\CommissionPayout;
use App\Models\Role;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\ConnectInformationNeeded;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeWebhookProcessor;
use App\Support\ConnectRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Partner payouts through Stripe Connect: account creation and prefill, the
 * embedded onboarding session, status sync, transfers, and the webhooks that
 * keep them current.
 *
 * Only Stripe's HTTP API is faked (Tests\Support\FakeStripe).
 */
class StripeConnectTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stripe.secret' => 'sk_test_fake',
            'stripe.key' => 'pk_test_fake',
            'stripe.connect.enabled' => true,
            'stripe.connect.requirement_collection' => 'application',
            'stripe.connect.tax_reporting' => true,
            'stripe.safeguards.enforce_connect_uniqueness' => true,
        ]);

        $this->stripe = new FakeStripe;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** A partner past the card gate; card capture is SubscriptionGateTest's concern. */
    private function partner(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['billing_exempt' => true]);
    }

    private function partnerWithAccount(array $account = []): User
    {
        $user = $this->partner();
        $user->stripe_connect_account_id = $this->stripe->connectedAccount('acct_'.$user->id, $account);
        $user->save();

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(
            ['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99],
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function connect(): StripeConnectService
    {
        return app(StripeConnectService::class);
    }

    private function approvedPayout(User $earner, float $amount = 125.50): CommissionPayout
    {
        return CommissionPayout::create(['earner_id' => $earner->id, 'total_amount' => $amount, 'status' => 'approved']);
    }

    private function deliver(string $type, array $object, ?string $account = null): void
    {
        $event = StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_'.uniqid(),
            'type' => $type,
            'event_created_at' => time(),
            'livemode' => false,
            'payload' => json_encode(array_filter(['account' => $account, 'data' => ['object' => $object]])),
            'signature' => 'test',
            'status' => StripeWebhookEvent::STATUS_RECEIVED,
            'processing_attempts' => 0,
            'received_at' => now(),
        ]);

        app(StripeWebhookProcessor::class)->process($event);

        $this->assertSame(StripeWebhookEvent::STATUS_PROCESSED, $event->fresh()->status, (string) $event->fresh()->processing_error);
    }

    // ── Account creation ──────────────────────────────────────────────────────

    public function test_accounts_are_created_embedded_with_tax_reporting_and_the_partner_prefilled(): void
    {
        $user = $this->partner([
            'name' => 'Jane Q Partner',
            'phone' => '(910) 555-0123',
            'address_line1' => '1038 Peterson Place',
            'city' => 'Wilmington',
            'state' => 'north carolina',
            'postal_code' => '28411',
            'country' => 'United States',
        ]);

        $accountId = $this->connect()->accountFor($user);
        $params = $this->stripe->paramsFor('post', '/v1/accounts')[0];

        $this->assertSame($accountId, $user->fresh()->stripe_connect_account_id);

        // No Stripe sign-in pop-up: the platform owns requirement collection.
        $this->assertSame('application', $params['controller']['requirement_collection']);
        $this->assertSame('none', $params['controller']['stripe_dashboard']['type']);
        $this->assertSame('application', $params['controller']['losses']['payments']);

        $this->assertSame('true', $params['capabilities']['transfers']['requested']);
        $this->assertSame('true', $params['capabilities'][StripeConnectService::TAX_CAPABILITY]['requested']);

        // Stripe reviews these: the company site, never a referral link.
        $this->assertSame('https://q3.life', $params['business_profile']['url']);
        $this->assertSame(
            'Referral commissions from product sales and marketing through Quantum 3 Solution platform',
            $params['business_profile']['product_description'],
        );

        $this->assertSame('individual', $params['business_type']);
        $this->assertSame([
            'first_name' => 'Jane Q',
            'last_name' => 'Partner',
            'email' => $user->email,
            'phone' => '+19105550123',
            'address' => [
                'line1' => '1038 Peterson Place',
                'city' => 'Wilmington',
                'state' => 'NC',
                'postal_code' => '28411',
                'country' => 'US',
            ],
        ], $params['individual']);
    }

    public function test_profile_details_stripe_would_refuse_are_left_out(): void
    {
        $user = $this->partner([
            'name' => 'Sam Rivers',
            'phone' => '555-0123',
            'address_line1' => '1 Main St',
            'city' => 'Toronto',
            'state' => 'Ontario',
            'postal_code' => 'M5V 2T6',
            'country' => 'Canada',
        ]);

        $this->connect()->accountFor($user);
        $individual = $this->stripe->paramsFor('post', '/v1/accounts')[0]['individual'];

        $this->assertSame('Sam', $individual['first_name']);
        $this->assertArrayNotHasKey('phone', $individual);
        $this->assertArrayNotHasKey('address', $individual);
    }

    public function test_a_refused_prefill_falls_back_to_an_account_without_it(): void
    {
        $user = $this->partner(['name' => 'Jane Partner', 'phone' => '9105550123']);
        $this->stripe->rejectPrefill = true;

        $accountId = $this->connect()->accountFor($user);
        $creates = $this->stripe->paramsFor('post', '/v1/accounts');

        $this->assertCount(2, $creates);
        $this->assertArrayNotHasKey('individual', $creates[1]);
        $this->assertArrayNotHasKey('business_type', $creates[1]);
        $this->assertSame('https://q3.life', $creates[1]['business_profile']['url']);
        $this->assertSame($accountId, $user->fresh()->stripe_connect_account_id);
    }

    public function test_an_existing_account_is_reused(): void
    {
        $user = $this->partnerWithAccount();

        $this->assertSame($user->stripe_connect_account_id, $this->connect()->accountFor($user));
        $this->assertSame([], $this->stripe->paramsFor('post', '/v1/accounts'));
    }

    // ── Get Paid ──────────────────────────────────────────────────────────────

    public function test_get_paid_needs_a_card_on_file_then_renders_the_embedded_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('member.payouts.index'))
            ->assertRedirect(route('member.billing.start'));

        $this->actingAs($this->partner())
            ->get(route('member.payouts.index'))
            ->assertOk()
            ->assertSee('Set Up Payouts')
            ->assertSee('https://connect-js.stripe.com/v1.0/connect.js', false);
    }

    public function test_the_onboarding_session_disables_stripe_login_on_every_component(): void
    {
        $user = $this->partner();

        $this->actingAs($user)
            ->postJson(route('member.payouts.account-session'))
            ->assertOk()
            ->assertExactJson(['client_secret' => 'accs_secret_fake']);

        $components = $this->stripe->paramsFor('post', '/v1/account_sessions')[0]['components'];

        $this->assertArrayHasKey('account_management', $components);

        // Stripe rejects the session unless this is identical on every component.
        foreach ($components as $name => $component) {
            $this->assertSame('true', $component['features']['disable_stripe_user_authentication'], $name);
        }
    }

    public function test_get_paid_is_unavailable_when_connect_is_switched_off(): void
    {
        config(['stripe.connect.enabled' => false]);

        $this->actingAs($this->partner())
            ->postJson(route('member.payouts.account-session'))
            ->assertStatus(422)
            ->assertJson(['error' => BillingException::connectDisabled()->getMessage()]);

        $this->assertSame([], $this->stripe->calls);
    }

    // ── Status sync ───────────────────────────────────────────────────────────

    public function test_sync_mirrors_payouts_tax_status_and_what_stripe_will_need_later(): void
    {
        $user = $this->partnerWithAccount([
            'payouts_enabled' => true,
            'details_submitted' => true,
            'capabilities' => ['transfers' => 'active', StripeConnectService::TAX_CAPABILITY => 'active'],
            'requirements' => ['eventually_due' => ['individual.dob.day', 'individual.dob.year']],
            'future_requirements' => ['currently_due' => ['individual.verification.document']],
        ]);

        $this->connect()->syncAccount($user);
        $user->refresh();

        $this->assertTrue($user->canReceivePayouts());
        $this->assertSame('active', $user->connect_tax_reporting_status);
        $this->assertSame([], $user->connectOutstandingRequirements());
        $this->assertSame(['Date of birth', 'Identity document'], ConnectRequirements::summarise($user->connectUpcomingRequirements()));
    }

    public function test_a_bank_account_already_paying_another_partner_is_refused(): void
    {
        $bank = ['external_accounts' => ['data' => [['id' => 'ba_1', 'object' => 'bank_account', 'fingerprint' => 'fp_bank']]]];
        $first = $this->partnerWithAccount($bank);
        $second = $this->partnerWithAccount($bank);

        $this->connect()->syncAccount($first);

        // A status refresh records the conflict without throwing.
        $this->connect()->syncAccount($second, enforceIdentity: false);

        $this->expectExceptionMessage(BillingException::duplicateConnectIdentity()->getMessage());
        $this->connect()->syncAccount($second);
    }

    // ── Paying out ────────────────────────────────────────────────────────────

    public function test_an_admin_pays_a_payout_through_stripe_and_it_is_marked_paid(): void
    {
        $partner = $this->partnerWithAccount(['payouts_enabled' => true]);
        $payout = $this->approvedPayout($partner);

        $this->actingAs($this->admin())
            ->post(route('admin.commission-payouts.send-transfer', $payout))
            ->assertSessionHas('success');

        $payout->refresh();
        $transfer = $this->stripe->paramsFor('post', '/v1/transfers')[0];

        $this->assertSame(12550, $transfer['amount']);
        $this->assertSame($partner->stripe_connect_account_id, $transfer['destination']);
        $this->assertSame('paid', $payout->status);
        $this->assertSame('paid', $payout->transfer_status);
        $this->assertSame('stripe_connect', $payout->payment_method);
        $this->assertSame($payout->stripe_transfer_id, $payout->payment_reference);
    }

    public function test_a_payout_is_not_sent_until_stripe_has_switched_payouts_on(): void
    {
        $partner = $this->partnerWithAccount(['requirements' => ['currently_due' => ['external_account']]]);
        $payout = $this->approvedPayout($partner);

        $this->actingAs($this->admin())
            ->post(route('admin.commission-payouts.send-transfer', $payout))
            ->assertSessionHas('error', "{$partner->name}'s payout account is not ready: Stripe still needs Bank account for payouts.");

        $this->assertSame([], $this->stripe->paramsFor('post', '/v1/transfers'));
        $this->assertSame('approved', $payout->fresh()->status);
    }

    public function test_a_low_platform_balance_is_explained_and_the_payout_stays_unpaid(): void
    {
        $partner = $this->partnerWithAccount(['payouts_enabled' => true]);
        $payout = $this->approvedPayout($partner);
        $this->stripe->transferError = 'balance_insufficient';

        $this->actingAs($this->admin())
            ->post(route('admin.commission-payouts.send-transfer', $payout))
            ->assertSessionHas('error');

        $payout->refresh();

        $this->assertStringContainsString('balance is too low', session('error'));
        $this->assertSame('approved', $payout->status);
        $this->assertSame('failed', $payout->transfer_status);
        $this->assertNull($payout->stripe_transfer_id);
    }

    public function test_an_admin_can_reverse_a_transfer(): void
    {
        $partner = $this->partnerWithAccount(['payouts_enabled' => true]);
        $payout = CommissionPayout::create([
            'earner_id' => $partner->id,
            'total_amount' => 40,
            'status' => 'paid',
        ]);
        $payout->forceFill(['stripe_transfer_id' => 'tr_123', 'transfer_status' => 'paid'])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.commission-payouts.reverse-transfer', $payout), ['reason' => 'Paid in error'])
            ->assertSessionHas('success');

        $this->assertCount(1, $this->stripe->paramsFor('post', '/v1/transfers/tr_123/reversals'));
        $this->assertSame('reversed', $payout->fresh()->transfer_status);
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    public function test_account_updated_resyncs_the_partner_from_stripe(): void
    {
        $partner = $this->partnerWithAccount();
        $accountId = $partner->stripe_connect_account_id;

        // Stripe has since switched payouts on; the payload itself is stale.
        $this->stripe->accounts[$accountId]['payouts_enabled'] = true;

        $this->deliver('account.updated', ['id' => $accountId, 'payouts_enabled' => false], $accountId);

        $this->assertTrue($partner->fresh()->connect_payouts_enabled);
    }

    public function test_a_reversed_transfer_is_recorded_on_the_payout(): void
    {
        $partner = $this->partner();
        $payout = CommissionPayout::create(['earner_id' => $partner->id, 'total_amount' => 40, 'status' => 'paid']);
        $payout->forceFill(['stripe_transfer_id' => 'tr_456', 'transfer_status' => 'paid'])->save();

        $this->deliver('transfer.reversed', ['id' => 'tr_456', 'reversed' => true]);

        $this->assertSame('reversed', $payout->fresh()->transfer_status);
    }

    // ── Admin: Payout Accounts ────────────────────────────────────────────────

    public function test_the_admin_list_shows_what_stripe_needs_in_words(): void
    {
        $partner = $this->partnerWithAccount(['requirements' => ['currently_due' => ['individual.dob.day', 'individual.dob.month']]]);
        $this->connect()->syncAccount($partner);

        $this->actingAs($this->admin())
            ->get(route('admin.billing.payout-accounts.index'))
            ->assertOk()
            ->assertSee($partner->name)
            ->assertSee('Date of birth');
    }

    public function test_request_information_emails_the_partner_the_exact_items(): void
    {
        Notification::fake();

        $partner = $this->partnerWithAccount(['requirements' => ['currently_due' => ['individual.dob.day', 'external_account']]]);
        $this->connect()->syncAccount($partner);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.payout-accounts.request', $partner))
            ->assertSessionHas('success');

        Notification::assertSentTo(
            $partner,
            ConnectInformationNeeded::class,
            fn (ConnectInformationNeeded $notification) => $notification->urgent
                && $notification->items === ['Date of birth', 'Bank account for payouts'],
        );
    }
}
