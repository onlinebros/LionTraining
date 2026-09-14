<?php

namespace Tests\Feature\Billing;

use App\Models\CardFingerprint;
use App\Models\PaymentMethod;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Stripe\StripeWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * `payment_method.attached` must never record a card the application refused.
 *
 * Stripe does not deliver events in order. A duplicate card is attached by the
 * browser and detached by the server moments later, so its `attached` event can
 * be processed after the detach.
 */
class CardWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stripe.secret' => 'sk_test_fake', 'stripe.key' => 'pk_test_fake']);

        $this->stripe = new FakeStripe();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function partner(): User
    {
        $user = User::factory()->create();
        $user->provider_customer_id = 'cus_'.$user->id;
        $user->save();

        return $user;
    }

    /** Deliver `payment_method.attached` for the payment method as it was when attached. */
    private function deliverAttached(string $paymentMethodId, User $to): StripeWebhookEvent
    {
        $object = $this->stripe->paymentMethods[$paymentMethodId];
        $object['customer'] = $to->provider_customer_id;

        $event = StripeWebhookEvent::create([
            'stripe_event_id'     => 'evt_'.uniqid(),
            'type'                => 'payment_method.attached',
            'event_created_at'    => time(),
            'livemode'            => false,
            'payload'             => json_encode(['data' => ['object' => $object]]),
            'signature'           => 'test',
            'status'              => StripeWebhookEvent::STATUS_RECEIVED,
            'processing_attempts' => 0,
            'received_at'         => now(),
        ]);

        app(StripeWebhookProcessor::class)->process($event);

        return $event->fresh();
    }

    public function test_a_card_held_by_another_account_is_not_recorded(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        CardFingerprint::claim('fp_shared', $first);

        // Already detached by the time the late event is processed.
        $this->stripe->card('pm_refused', 'fp_shared');

        $event = $this->deliverAttached('pm_refused', $second);

        $this->assertSame(StripeWebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertFalse(PaymentMethod::where('user_id', $second->id)->exists());
    }

    public function test_a_card_removed_before_the_event_arrives_is_not_recreated(): void
    {
        $user = $this->partner();

        $this->stripe->card('pm_removed', 'fp_removed');

        $this->deliverAttached('pm_removed', $user);

        $this->assertFalse(PaymentMethod::where('provider_payment_method_id', 'pm_removed')->exists());
    }

    public function test_a_card_still_attached_is_recorded_and_claimed(): void
    {
        $user = $this->partner();

        $this->stripe->card('pm_new', 'fp_new', $user->provider_customer_id);

        $this->deliverAttached('pm_new', $user);

        $this->assertTrue(PaymentMethod::where('provider_payment_method_id', 'pm_new')->where('user_id', $user->id)->exists());
        $this->assertSame($user->id, (int) CardFingerprint::where('fingerprint', 'fp_new')->value('user_id'));
    }
}
