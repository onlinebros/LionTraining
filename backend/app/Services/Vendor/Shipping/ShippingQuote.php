<?php

namespace App\Services\Vendor\Shipping;

/**
 * One shipping figure, and where it came from.
 *
 * `source` is not decoration. A customer disputing a $340 shipping charge, or a
 * vendor disputing an invoice, is answered by "FedEx Ground, negotiated rate,
 * quoted at 14:02" and is not answered by a number on its own.
 */
final readonly class ShippingQuote
{
    public const SOURCE_CARRIER  = 'carrier';       // live rate from the carrier
    public const SOURCE_FLAT     = 'flat';          // configured fallback
    public const SOURCE_FREE     = 'free';          // absorbed into the price
    public const SOURCE_MANUAL   = 'manual';        // a human quoted it
    public const SOURCE_FREIGHT  = 'freight_quote'; // too big for parcel — needs a human

    public function __construct(
        /** Minor units. */
        public int $amount,
        public string $source,
        public ?string $service = null,
        public ?string $carrier = null,
        /** True when the order must not be self-served — freight, or no rate available. */
        public bool $requiresHuman = false,
        public ?string $reason = null,
    ) {}

    public static function freight(string $reason): self
    {
        return new self(
            amount: 0,
            source: self::SOURCE_FREIGHT,
            requiresHuman: true,
            reason: $reason,
        );
    }

    public function isQuotable(): bool
    {
        return ! $this->requiresHuman;
    }

    public function describe(): string
    {
        return trim(implode(' ', array_filter([
            $this->carrier,
            $this->service,
            $this->source === self::SOURCE_CARRIER ? '(live rate)' : "({$this->source})",
        ])));
    }
}
