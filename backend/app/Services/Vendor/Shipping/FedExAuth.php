<?php

namespace App\Services\Vendor\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The OAuth bearer token for our FedEx developer project.
 *
 * Shared by rating and address validation, which use the same project, so one
 * cached token serves both.
 */
final class FedExAuth
{
    /**
     * A token cached just short of its hour.
     *
     * Fetching one per request would double the latency of every address change
     * on the checkout for no benefit.
     */
    public static function token(): ?string
    {
        return Cache::remember('fedex:token:'.config('fedex.mode'), (int) config('fedex.token_ttl', 3300), function () {
            $response = Http::asForm()
                ->timeout((int) config('fedex.timeout', 12))
                ->post(config('fedex.host').'/oauth/token', [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => config('fedex.key'),
                    'client_secret' => config('fedex.secret'),
                ]);

            if (! $response->successful()) {
                Log::warning('FedEx OAuth failed', [
                    'status' => $response->status(),
                    'error'  => $response->json('errors.0.message') ?? $response->body(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }
}
