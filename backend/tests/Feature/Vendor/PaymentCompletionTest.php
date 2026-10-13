<?php

namespace Tests\Feature\Vendor;

use App\Mail\VendorOrderPlaced;
use App\Mail\VendorPaymentIncomplete;
use App\Models\VendorLead;
use App\Services\Vendor\PaymentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * What the buyer is told when they come back from checkout.
 *
 * Buyers who backed out of Affirm were shown "your order is placed" and took
 * it at its word. Only a payment the vendor's Stripe reports as succeeded may
 * be called an order; everything else says so, and the buyer is emailed what
 * to look out for to finish.
 */
class PaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.stripe.secret', 'rk_test_fake');

        $this->stripe = new FakeStripe;
        ApiRequestor::setHttpClient($this->stripe);
        Cache::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_cancelled_affirm_checkout_is_not_called_an_order(): void
    {
        $lead = $this->order('pi_affirm_cancel');
        $this->intent('pi_affirm_cancel', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'payment_intent_payment_attempt_failed', 'decline_code' => 'affirm_checkout_canceled', 'payment_method' => ['type' => 'affirm']]);

        // The redirect claims success; the page must not believe it.
        $this->get(route('vendor.complete', $lead->public_ref).'?redirect_status=succeeded')
            ->assertOk()
            ->assertDontSee('is placed')
            ->assertSee('Your order was not completed')
            ->assertSee('Affirm did not approve it')
            ->assertSee('No payment was taken')
            ->assertSee(route('vendor.order', $lead->public_ref));

        Mail::assertQueued(VendorPaymentIncomplete::class, function (VendorPaymentIncomplete $mail) use ($lead) {
            return $mail->hasTo('dana@example.com')
                && $mail->state === PaymentState::NOT_PAID
                && str_contains($mail->envelope()->subject, 'was not completed');
        });
        Mail::assertNotQueued(VendorOrderPlaced::class);
        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
    }

    public function test_reloading_the_page_does_not_email_again(): void
    {
        $lead = $this->order('pi_reload');
        $this->intent('pi_reload', 'requires_payment_method',
            error: ['code' => 'payment_intent_payment_attempt_expired', 'payment_method' => ['type' => 'klarna']]);

        $this->get(route('vendor.complete', $lead->public_ref))->assertOk();
        $this->get(route('vendor.complete', $lead->public_ref))->assertOk();

        Mail::assertQueuedCount(1);
    }

    public function test_an_unfinished_affirm_application_says_what_finished_looks_like(): void
    {
        $lead = $this->order('pi_affirm_open');
        $this->stripe->paymentMethods['pm_affirm'] = ['id' => 'pm_affirm', 'object' => 'payment_method', 'type' => 'affirm'];
        $this->intent('pi_affirm_open', 'requires_action', paymentMethod: 'pm_affirm', nextAction: 'redirect_to_url');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertDontSee('is placed')
            ->assertSee("Your Affirm payment isn&#039;t finished", false)
            ->assertSee('Payment option you chose')
            ->assertSee('Affirm — pay over time with an Affirm loan')
            ->assertSee('Affirm emails you to confirm your loan')
            ->assertSee('12 hours')
            ->assertSee('Return to checkout');

        Mail::assertQueued(VendorPaymentIncomplete::class, fn ($mail) => $mail->state === PaymentState::ACTION && $mail->method === 'affirm');
    }

    public function test_an_unfinished_klarna_checkout_gets_klarnas_terms(): void
    {
        $lead = $this->order('pi_klarna_open');
        $this->stripe->paymentMethods['pm_klarna'] = ['id' => 'pm_klarna', 'object' => 'payment_method', 'type' => 'klarna'];
        $this->intent('pi_klarna_open', 'requires_action', paymentMethod: 'pm_klarna', nextAction: 'redirect_to_url');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Klarna Financing — pay over time with Klarna')
            ->assertSee('Klarna offers only its Financing option')
            ->assertSee('3 hours');
    }

    public function test_a_clearing_bank_payment_is_pending_and_cannot_be_paid_twice(): void
    {
        $lead = $this->order('pi_ach');
        $this->intent('pi_ach', 'processing', charge: 'us_bank_account');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertDontSee('is placed')
            ->assertSee('NOT confirmed yet')
            ->assertSee('up to 4 business days')
            ->assertDontSee('Return to checkout');

        Mail::assertQueued(VendorPaymentIncomplete::class, fn ($mail) => $mail->state === PaymentState::PENDING);

        $lead->forceFill(['address_status' => 'verified'])->save();
        $this->postJson(route('vendor.order.pay', $lead->public_ref))->assertStatus(409);
    }

    public function test_microdeposit_verification_is_explained(): void
    {
        $lead = $this->order('pi_micro');
        $this->intent('pi_micro', 'requires_action', nextAction: 'verify_with_microdeposits');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Verify your bank account')
            ->assertSee('ACCTVERIFY')
            ->assertSee('10 days');
    }

    public function test_only_a_succeeded_payment_is_called_an_order(): void
    {
        $lead = $this->order('pi_paid');
        $this->intent('pi_paid', 'succeeded', charge: 'affirm');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Thank you, Dana')
            ->assertSee('Payment received')
            ->assertSee('is placed')
            ->assertSee('Affirm emails you to confirm your loan');

        Mail::assertNotQueued(VendorPaymentIncomplete::class);
    }

    public function test_an_authorisation_is_not_funds(): void
    {
        $lead = $this->order('pi_auth');
        $this->intent('pi_auth', 'requires_capture', charge: 'card');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertDontSee('is placed')
            ->assertSee('not received yet');
    }

    public function test_a_converted_order_is_paid_without_asking_stripe(): void
    {
        $lead = $this->order('pi_converted', VendorLead::STATUS_CONVERTED);

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('is placed');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_an_unreadable_status_is_neither_placed_nor_failed(): void
    {
        // No intent in the fake: Stripe answers 404.
        $lead = $this->order('pi_missing');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertDontSee('is placed')
            ->assertDontSee('was not completed')
            ->assertSee('still confirming your payment');

        Mail::assertNothingQueued();
    }

    public function test_a_declined_card_says_so_and_points_to_the_bank(): void
    {
        $lead = $this->order('pi_card_declined');
        $this->intent('pi_card_declined', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'payment_method' => ['type' => 'card']]);

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Your card was declined.')
            ->assertSee('Your bank can tell you why')
            ->assertDontSee('Affirm');
    }

    public function test_a_cancelled_klarna_checkout_is_not_called_a_decline(): void
    {
        $lead = $this->order('pi_klarna_cancel');
        $this->intent('pi_klarna_cancel', 'requires_payment_method',
            error: ['code' => 'payment_method_customer_decline', 'payment_method' => ['type' => 'klarna']]);

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Your Klarna payment was cancelled or timed out')
            ->assertDontSee('did not approve')
            ->assertSee('If you choose Klarna again');
    }

    public function test_an_unfinished_cash_app_payment_names_its_limits(): void
    {
        $lead = $this->order('pi_cashapp');
        $this->stripe->paymentMethods['pm_cashapp'] = ['id' => 'pm_cashapp', 'object' => 'payment_method', 'type' => 'cashapp'];
        $this->intent('pi_cashapp', 'requires_action', paymentMethod: 'pm_cashapp', nextAction: 'cashapp_handle_redirect_or_display_qr_code');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertOk()
            ->assertSee('Approve the payment in Cash App')
            ->assertSee('about 10 minutes')
            ->assertSee('spending limits');
    }

    public function test_microdeposit_emails_come_from_the_seller_not_us(): void
    {
        $lead = $this->order('pi_micro_from');
        $this->intent('pi_micro_from', 'requires_action', nextAction: 'verify_with_microdeposits');

        $this->get(route('vendor.complete', $lead->public_ref))
            ->assertSee('on PlasmaGuard&#039;s behalf', false)
            ->assertDontSee('on our behalf');
    }

    public function test_the_buyer_email_says_it_is_not_a_confirmation(): void
    {
        $lead = $this->order('pi_mail');

        $html = (new VendorPaymentIncomplete($lead, PaymentState::ACTION, 'affirm', false, 'redirect_to_url'))->render();

        $this->assertStringContainsString('This email is not an order confirmation', $html);
        $this->assertStringContainsString('No order has been placed and nothing will ship', $html);
        $this->assertStringContainsString('Affirm emails you to confirm your loan', $html);
        $this->assertStringContainsString('Payment option you chose', $html);
        $this->assertStringContainsString(route('vendor.order', $lead->public_ref), $html);
    }

    private function order(string $intentId, string $status = VendorLead::STATUS_HANDED_OFF): VendorLead
    {
        return VendorLead::create([
            'public_ref'                 => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'                     => 'plasmaguard',
            'product_key'                => 'pro-in-duct',
            'first_name'                 => 'Dana',
            'last_name'                  => 'Harwood',
            'email'                      => 'dana@example.com',
            'quantity'                   => 1,
            'status'                     => $status,
            'handed_off_at'              => now()->subHour(),
            'converted_at'               => $status === VendorLead::STATUS_CONVERTED ? now() : null,
            'provider_payment_intent_id' => $intentId,
            'amount_total'               => 604672,
            'currency'                   => 'USD',
        ]);
    }

    /** @param  array<string,mixed>|null  $error */
    private function intent(
        string $id,
        string $status,
        ?array $error = null,
        ?string $charge = null,
        ?string $paymentMethod = null,
        ?string $nextAction = null,
    ): void {
        $this->stripe->paymentIntents[$id] = [
            'id'                 => $id,
            'object'             => 'payment_intent',
            'status'             => $status,
            'amount'             => 604672,
            'payment_method'     => $paymentMethod,
            'last_payment_error' => $error,
            'next_action'        => $nextAction === null ? null : ['type' => $nextAction],
            'latest_charge'      => $charge === null ? null : [
                'id'                     => 'ch_'.$id,
                'object'                 => 'charge',
                'payment_method_details' => ['type' => $charge],
            ],
        ];
    }
}
