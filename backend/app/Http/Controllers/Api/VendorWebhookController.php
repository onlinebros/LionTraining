<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IngestsSignedWebhooks;
use App\Http\Controllers\Controller;
use App\Support\Vendors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sale confirmations from a vendor's own payment account.
 *
 * One URL per vendor, each verified against that vendor's signing secret. The
 * slug is in the path rather than sniffed from the payload so a secret can only
 * ever validate events for the vendor it belongs to: an event signed by vendor
 * A and posted to vendor B's URL fails verification, which is the entire point
 * of not sharing an endpoint.
 *
 * Until a vendor supplies a secret this answers 503, and conversions for them
 * are marked by hand from the admin screen. That is the designed fallback —
 * PlasmaGuard may never provide one — not a broken state.
 */
class VendorWebhookController extends Controller
{
    use IngestsSignedWebhooks;

    public function handle(Request $request, string $vendor): JsonResponse
    {
        if (Vendors::find($vendor) === null) {
            return response()->json(['error' => 'unknown_vendor'], 404);
        }

        return $this->ingest(
            $request,
            Vendors::endpointKey($vendor),
            Vendors::webhookSecret($vendor),
        );
    }
}
