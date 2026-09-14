<?php

namespace Tests\Feature\Billing;

use App\Exceptions\BillingException;
use App\Models\CardFingerprint;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Stripe\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * One physical card, one account, for good.
 *
 * Only Stripe's HTTP API is faked (Tests\Support\FakeStripe). Everything above
 * the network call is the code that runs in production.
 */
class CardUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stripe.secret' => 'sk_test_fake',
            'stripe.key'    => 'pk_test_fake',
            'stripe.safeguards.enforce_card_uniqueness' => true,
        ]);

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

        // Assigned directly, as BillingService::customerFor() does.
        $user->provider_customer_id = 'cus_'.$user->id;
        $user->save();

        return $user;
    }

    /** What the browser leaves behind: a card the SetupIntent attached to this customer. */
    private function confirmedCard(User $user, string $id, string $fingerprint, array $card = []): string
    {
        return $this->stripe->card($id, $fingerprint, $user->provider_customer_id, $card);
    }

    private function save(User $user, string $paymentMethodId): PaymentMethod
    {
        return app(BillingService::class)->attachPaymentMethod($user, $paymentMethodId);
    }

    private function remove(User $user, string $paymentMethodId): void
    {
        app(BillingService::class)->detachPaymentMethod(
            $user,
            PaymentMethod::where('provider_payment_method_id', $paymentMethodId)->firstOrFail(),
        );
    }

    private function assertRefused(User $user, string $paymentMethodId, BillingException $expected): void
    {
        try {
            $this->save($user, $paymentMethodId);
            $this->fail('The card was accepted.');
        } catch (BillingException $e) {
            $this->assertSame($expected->getMessage(), $e->getMessage());
        }

        $this->assertTrue($this->stripe->wasDetached($paymentMethodId), 'A refused card must not stay on the customer.');
        $this->assertFalse(PaymentMethod::where('provider_payment_method_id', $paymentMethodId)->exists());
    }

    public function test_a_card_saved_on_one_account_is_refused_on_another(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        $this->save($first, $this->confirmedCard($first, 'pm_first', 'fp_shared'));

        $this->assertRefused($second, $this->confirmedCard($second, 'pm_second', 'fp_shared'), BillingException::duplicateCard());
        $this->assertSame($first->id, (int) CardFingerprint::where('fingerprint', 'fp_shared')->value('user_id'));
    }

    public function test_a_different_card_is_accepted_on_the_second_account(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        $this->save($first, $this->confirmedCard($first, 'pm_first', 'fp_one'));
        $saved = $this->save($second, $this->confirmedCard($second, 'pm_second', 'fp_two'));

        $this->assertSame($second->id, $saved->user_id);
        $this->assertFalse($this->stripe->wasDetached('pm_second'));
    }

    public function test_removing_the_card_does_not_free_it_for_another_account(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        $this->save($first, $this->confirmedCard($first, 'pm_old', 'fp_shared'));
        $this->save($first, $this->confirmedCard($first, 'pm_new', 'fp_other'));
        $this->remove($first, 'pm_old');

        $this->assertFalse(PaymentMethod::where('fingerprint', 'fp_shared')->exists());
        $this->assertRefused($second, $this->confirmedCard($second, 'pm_again', 'fp_shared'), BillingException::duplicateCard());
    }

    public function test_deleting_the_account_does_not_free_its_card(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        $this->save($first, $this->confirmedCard($first, 'pm_first', 'fp_shared'));
        $first->delete();

        $this->assertNull(CardFingerprint::where('fingerprint', 'fp_shared')->value('user_id'));
        $this->assertRefused($second, $this->confirmedCard($second, 'pm_second', 'fp_shared'), BillingException::duplicateCard());
    }

    public function test_an_account_can_save_its_own_card_again(): void
    {
        $user = $this->partner();

        $this->save($user, $this->confirmedCard($user, 'pm_before', 'fp_mine'));
        $this->remove($user, 'pm_before');

        $saved = $this->save($user, $this->confirmedCard($user, 'pm_after', 'fp_mine'));

        $this->assertSame('pm_after', $saved->provider_payment_method_id);
    }

    public function test_wallet_cards_are_refused(): void
    {
        $user = $this->partner();

        $this->assertRefused(
            $user,
            $this->confirmedCard($user, 'pm_wallet', 'fp_device', ['wallet' => ['type' => 'apple_pay']]),
            BillingException::walletNotAccepted(),
        );
        $this->assertFalse(CardFingerprint::where('fingerprint', 'fp_device')->exists());
    }

    public function test_payment_methods_that_are_not_cards_are_refused(): void
    {
        $user = $this->partner();

        // A Link or bank payment method has no card, so no fingerprint to check.
        $this->stripe->paymentMethods['pm_link'] = [
            'id'       => 'pm_link',
            'object'   => 'payment_method',
            'type'     => 'link',
            'customer' => $user->provider_customer_id,
            'link'     => ['email' => 'someone@example.com'],
        ];

        $this->assertRefused($user, 'pm_link', BillingException::cardRequired());
    }

    public function test_cards_saved_before_the_ledger_existed_still_count(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        PaymentMethod::create([
            'user_id'                    => $first->id,
            'provider_payment_method_id' => 'pm_legacy',
            'fingerprint'                => 'fp_legacy',
        ]);

        $this->assertRefused($second, $this->confirmedCard($second, 'pm_second', 'fp_legacy'), BillingException::duplicateCard());
    }

    public function test_a_card_attached_to_another_customer_is_refused_without_being_moved(): void
    {
        $first  = $this->partner();
        $second = $this->partner();

        $this->stripe->card('pm_theirs', 'fp_theirs', $first->provider_customer_id);

        $this->expectExceptionMessage(BillingException::duplicateCard()->getMessage());

        try {
            $this->save($second, 'pm_theirs');
        } finally {
            $this->assertSame($first->provider_customer_id, $this->stripe->paymentMethods['pm_theirs']['customer']);
        }
    }
}
