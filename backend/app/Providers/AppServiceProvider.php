<?php

namespace App\Providers;

use App\Http\ViewComposers\SiteSettingsComposer;
use App\Services\Genealogy\PlacementStrategy;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Shipping rates for vendor orders. Flat until PlasmaGuard supply FedEx
         * API credentials for their negotiated rates — swapping in a carrier
         * rater is this binding and nothing else.
         */
        $this->app->bind(\App\Services\Vendor\Shipping\ShippingRater::class, function () {
            $vendor = 'plasmaguard';

            // FedEx once its credentials are present, the flat fallback until
            // then. Neither the checkout nor the order service knows which.
            return filled(config('fedex.key'))
                ? new \App\Services\Vendor\Shipping\FedExShippingRater($vendor)
                : new \App\Services\Vendor\Shipping\FlatShippingRater($vendor);
        });

        // The placement structure is a config choice — see config/genealogy.php.
        // Resolved here so nothing downstream of GenealogyService has to know
        // which structure is running.
        $this->app->bind(PlacementStrategy::class, function () {
            $structure = config('genealogy.structure');
            $class = config("genealogy.strategies.{$structure}");

            if ($class === null) {
                throw new InvalidArgumentException(
                    "Unknown genealogy structure [{$structure}]. Add it to config/genealogy.php."
                );
            }

            return $this->app->make($class);
        });
    }

    public function boot(): void
    {
        // Inject $siteSettings into every layout so logo/color/mode are admin-controlled
        View::composer(['layouts.admin', 'layouts.member', 'layouts.public'], SiteSettingsComposer::class);

        RedirectIfAuthenticated::redirectUsing(fn () => route('member.dashboard'));

        // Stripe notices become log entries rather than warnings that Laravel
        // turns into 500s; see NoticeLoggingHttpClient. Only Stripe's default
        // client is wrapped, so a test double installed later is left alone.
        if (\Stripe\ApiRequestor::httpClient() instanceof \Stripe\HttpClient\CurlClient) {
            \Stripe\ApiRequestor::setHttpClient(
                new \App\Services\Stripe\NoticeLoggingHttpClient(\Stripe\HttpClient\CurlClient::instance())
            );
        }

        // Public website contact form: 10 requests per 10 minutes per client IP.
        // Only per *visitor* if request()->ip() is the visitor's address, which
        // depends on the proxy setup in front of the app.
        \Illuminate\Support\Facades\RateLimiter::for('support-requests', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 10)->by('support-requests|'.$request->ip());
        });
    }
}
