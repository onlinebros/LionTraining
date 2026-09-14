<?php

namespace Tests\Feature\Billing;

use App\Services\Stripe\NoticeLoggingHttpClient;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Stripe\Util\CaseInsensitiveArray;
use Tests\TestCase;

/**
 * A Stripe-Notice header must never fail a request.
 *
 * stripe-php raises the notice as E_USER_WARNING, which Laravel converts to an
 * exception: without the wrapper this test fails exactly the way every Get Paid
 * page did.
 */
class StripeNoticeTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_stripe_notice_is_logged_instead_of_failing_the_call(): void
    {
        Log::spy();

        $stripe = new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [
                    json_encode(['id' => 'acct_1', 'object' => 'account']),
                    200,
                    new CaseInsensitiveArray([
                        'Stripe-Notice' => 'We recommend building your integration using Accounts v2.',
                        'Request-Id' => 'req_1',
                    ]),
                ];
            }
        };

        ApiRequestor::setHttpClient(new NoticeLoggingHttpClient($stripe));

        $account = (new StripeClient(['api_key' => 'sk_test_fake']))->accounts->retrieve('acct_1');

        $this->assertSame('acct_1', $account->id);

        Log::shouldHaveReceived('notice')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Stripe API notice'
                && str_contains($context['notice'], 'Accounts v2'),
        );
    }
}
