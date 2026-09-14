<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IngestsSignedWebhooks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment webhook landing pad for OUR Stripe account.
 *
 * Two endpoints, two secrets. Platform-account events and connected-account
 * events arrive separately and are verified against their own secret — a
 * Connect event presented to the platform endpoint has to fail, or the split is
 * decorative.
 *
 * A third kind of event now exists — a vendor's own account confirming a sale
 * one of our partners referred. That lands on VendorWebhookController, with the
 * vendor's secret and its own processor, because nothing in a vendor's payload
 * resolves against our account.
 *
 * The verify/record/dispatch mechanics live in IngestsSignedWebhooks, shared
 * with that controller.
 */
class StripeWebhookController extends Controller
{
    use IngestsSignedWebhooks;

    public const ENDPOINT_ACCOUNT = 'account';
    public const ENDPOINT_CONNECT = 'connect';

    public function handle(Request $request): JsonResponse
    {
        return $this->ingest($request, self::ENDPOINT_ACCOUNT, (string) config('stripe.webhook_secret'));
    }

    public function handleConnect(Request $request): JsonResponse
    {
        return $this->ingest($request, self::ENDPOINT_CONNECT, (string) config('stripe.webhook_connect_secret'));
    }
}
