<?php

namespace App\Support;

/**
 * Read-only accessor over config/vendors.php.
 *
 * Exists so controllers, views and the service all reach the registry the same
 * way, and so a missing vendor or product is one exception type rather than an
 * "undefined array key" surfacing three layers down.
 */
class Vendors
{
    /** @return array<string,mixed> */
    public static function all(): array
    {
        return (array) config('vendors.vendors', []);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $slug): ?array
    {
        $vendor = config("vendors.vendors.{$slug}");

        return is_array($vendor) ? $vendor : null;
    }

    /** @return array<string,mixed>|null */
    public static function product(string $slug, string $productKey): ?array
    {
        $product = config("vendors.vendors.{$slug}.products.{$productKey}");

        return is_array($product) ? $product : null;
    }

    /**
     * Is this vendor open for business?
     *
     * A vendor whose terms are still being negotiated stays registered but
     * switched off, so its pages can be demonstrated internally without being
     * shareable to the public.
     */
    public static function enabled(string $slug): bool
    {
        return (bool) (self::find($slug)['enabled'] ?? false);
    }

    public static function name(string $slug): string
    {
        return (string) (self::find($slug)['name'] ?? $slug);
    }

    /** The signing secret belonging to the VENDOR's payment account, not ours. */
    public static function webhookSecret(string $slug): string
    {
        return (string) (self::find($slug)['webhook_secret'] ?? '');
    }

    public static function commissionRate(string $slug): float
    {
        return (float) (self::find($slug)['commission']['rate'] ?? 0.0);
    }

    /**
     * Our cut of one order, in minor units — the Connect application fee.
     *
     * Quantity is an argument rather than something the caller multiplies
     * afterwards, because "forgot to multiply by quantity" is a silent
     * three-thousand-dollar error per unit and there should be exactly one
     * place it can be got wrong.
     *
     * Computed from the product subtotal only. Never shipping (the carrier's
     * money), never the handling fee, and never the tax — taking a share of
     * collected sales tax puts someone else's liability in our balance.
     *
     * @param  int  $subtotalMinor  Product subtotal for the order, ex shipping and tax.
     *
     * @throws \RuntimeException when the share exceeds the goods it is a share of.
     */
    public static function revenueShare(
        string $slug,
        string $productKey,
        int $quantity,
        int $subtotalMinor,
    ): int {
        $vendor  = self::find($slug);
        $product = self::product($slug, $productKey);

        if ($vendor === null || $product === null || $quantity < 1) {
            return 0;
        }

        $share = match ($vendor['revenue_share']['model'] ?? 'per_unit') {
            'rate'  => (int) round($subtotalMinor * (float) ($vendor['revenue_share']['rate'] ?? 0.0)),
            default => (int) ($product['revenue_share_per_unit'] ?? 0) * $quantity,
        };

        if ($share < 0) {
            return 0;
        }

        /*
         * A share larger than the goods means the vendor nets nothing or less
         * on a sale they are fulfilling and warrantying. That is a
         * misconfiguration — a per-unit figure left standing after a price cut,
         * most likely — and the only safe response is to refuse to build the
         * charge. Clamping would hide it and still take every cent.
         */
        if ($share > $subtotalMinor) {
            throw new \RuntimeException(sprintf(
                'Revenue share (%d) exceeds the product subtotal (%d) for %s/%s at qty %d. '
                .'Check revenue_share_per_unit against the current price.',
                $share, $subtotalMinor, $slug, $productKey, $quantity,
            ));
        }

        return $share;
    }

    public static function clawbackDays(string $slug): int
    {
        return (int) (self::find($slug)['commission']['clawback_days'] ?? 0);
    }

    public static function matchWindowDays(string $slug): int
    {
        return (int) (self::find($slug)['match_window_days'] ?? 30);
    }

    /** 'test' or 'live' — which credential set this machine is using. */
    public static function stripeMode(string $slug): string
    {
        return (string) (self::find($slug)['stripe']['mode'] ?? 'test');
    }

    public static function isLiveMode(string $slug): bool
    {
        return self::stripeMode($slug) === 'live';
    }

    /** The endpoint discriminator written on webhook ledger rows. */
    public static function endpointKey(string $slug): string
    {
        return "vendor:{$slug}";
    }

    /** Slug back out of an endpoint discriminator, or null if not a vendor row. */
    public static function slugFromEndpoint(string $endpoint): ?string
    {
        return str_starts_with($endpoint, 'vendor:')
            ? substr($endpoint, strlen('vendor:'))
            : null;
    }
}
