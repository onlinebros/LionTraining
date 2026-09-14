<?php

namespace App\Services\Vendor;

use App\Support\Vendors;
use RuntimeException;
use Stripe\StripeClient;

/**
 * A StripeClient authenticated as a VENDOR, not as us.
 *
 * Deliberately separate from StripeClientFactory. That builds a client on our
 * own secret key for our own account; this one carries a third party's
 * restricted key and every call it makes creates objects on their books. Two
 * different sets of consequences should not share one accessor that a caller
 * could pick the wrong branch of by accident.
 *
 * The keys are restricted — scoped by PlasmaGuard to payments, customers and
 * tax, with no access to their balance, payouts or charge history. Anything
 * here that needs more than that is a design error rather than a permissions
 * problem to be solved by asking for a wider key.
 */
class VendorStripeClient
{
    /** @var array<string,StripeClient> */
    private array $clients = [];

    public function for(string $vendorSlug): StripeClient
    {
        if (isset($this->clients[$vendorSlug])) {
            return $this->clients[$vendorSlug];
        }

        $secret = (string) (Vendors::find($vendorSlug)['stripe']['secret'] ?? '');

        if ($secret === '') {
            throw new RuntimeException(
                "No Stripe credentials configured for vendor '{$vendorSlug}'. "
                ."Set the vendor's restricted key before attempting to build an order."
            );
        }

        return $this->clients[$vendorSlug] = new StripeClient([
            'api_key'        => $secret,
            'stripe_version' => config('stripe.api_version'),
        ]);
    }

    public function isConfigured(string $vendorSlug): bool
    {
        return filled(Vendors::find($vendorSlug)['stripe']['secret'] ?? null);
    }

    /** The vendor's publishable key — handed to the browser to mount the card fields. */
    public function publishableKey(string $vendorSlug): ?string
    {
        return Vendors::find($vendorSlug)['stripe']['key'] ?? null;
    }

    /**
     * Live keys on a vendor account move real money belonging to someone else.
     * Screens that can place an order say which mode they are in, so nobody
     * discovers it from a customer's card statement.
     */
    public function isTestMode(string $vendorSlug): bool
    {
        $secret = (string) (Vendors::find($vendorSlug)['stripe']['secret'] ?? '');

        return str_contains($secret, '_test_');
    }
}
