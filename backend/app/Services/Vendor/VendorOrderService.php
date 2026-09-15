<?php

namespace App\Services\Vendor;

use App\Models\VendorLead;
use App\Services\Vendor\Shipping\ShippingQuote;
use App\Services\Vendor\Shipping\ShippingRater;
use App\Support\Vendors;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Builds and places an order on the vendor's Stripe account.
 *
 * Two responsibilities that are deliberately separate:
 *
 *   quote()  — pure arithmetic plus a tax lookup. No side effects, safe to call
 *              on every keystroke of an address form.
 *   place()  — creates the customer and the PaymentIntent on the vendor's
 *              account and writes the breakdown back to the lead.
 *
 * The money rule throughout: the vendor's confirmation is truth. Everything
 * computed here is an estimate until `payment_intent.succeeded` says otherwise.
 */
class VendorOrderService
{
    public function __construct(
        private readonly VendorStripeClient $stripe,
        private readonly ShippingRater $rater,
    ) {}

    /**
     * Price an order without touching anything.
     *
     * @return array{
     *     subtotal:int, shipping:int, handling:int, tax:int, total:int,
     *     our_share:int, cartons:int, shipping_quote:ShippingQuote,
     *     tax_calculation:?string, quotable:bool, reason:?string
     * }
     */
    public function quote(VendorLead $lead): array
    {
        $vendor  = Vendors::find($lead->vendor)   ?? throw new RuntimeException("Unknown vendor {$lead->vendor}");
        $product = Vendors::product($lead->vendor, $lead->product_key)
            ?? throw new RuntimeException("Unknown product {$lead->product_key}");

        $qty     = max(1, (int) $lead->quantity);
        $unit    = (int) round(((float) ($product['price'] ?? 0)) * 100);

        if ($unit <= 0) {
            throw new RuntimeException(
                "No price configured for {$lead->vendor}/{$lead->product_key}. "
                .'An order cannot be built against an unpriced product.'
            );
        }

        $subtotal = $unit * $qty;

        // One carton per unit for this product — three systems is three parcels.
        $perCarton = max(1, (int) ($product['parcel']['units_per_carton'] ?? 1));
        $cartons   = (int) ceil($qty / $perCarton);

        $shippingQuote = $this->rater->quote(
            origin: $vendor['pricing']['shipping']['origin'] ?? [],
            // The address check's classification, so a home is rated as a home.
            destination: $this->destination($lead) + ['residential' => $lead->address_classification === 'RESIDENTIAL'],
            parcel: $product['parcel'] ?? [],
            cartons: $cartons,
        );

        if (! $shippingQuote->isQuotable()) {
            return [
                'subtotal' => $subtotal, 'shipping' => 0, 'handling' => 0, 'tax' => 0,
                'total' => 0, 'our_share' => 0, 'cartons' => $cartons,
                'shipping_quote' => $shippingQuote, 'tax_calculation' => null,
                'quotable' => false, 'reason' => $shippingQuote->reason,
            ];
        }

        $handling = $this->handling($vendor, $qty);

        /*
         * Shipping and the handling fee are charged as one delivery line. Several
         * states tax a delivery charge in full when handling is bundled into it
         * while treating pure carriage differently, so splitting them invites a
         * tax treatment nobody chose. One line, one tax code.
         */
        $delivery = $shippingQuote->amount + $handling;

        [$tax, $taxCalculationId] = $this->tax($lead, $subtotal, $delivery);

        return [
            'subtotal'        => $subtotal,
            'shipping'        => $shippingQuote->amount,
            'handling'        => $handling,
            'tax'             => $tax,
            'total'           => $subtotal + $delivery + $tax,
            'our_share'       => Vendors::revenueShare($lead->vendor, $lead->product_key, $qty, $subtotal),
            'cartons'         => $cartons,
            'shipping_quote'  => $shippingQuote,
            'tax_calculation' => $taxCalculationId,
            'quotable'        => true,
            'reason'          => null,
        ];
    }

    /**
     * Create the order on the vendor's Stripe account.
     *
     * Returns the PaymentIntent client secret for the browser to confirm. The
     * charge is NOT confirmed here — the customer's card details never touch
     * this server, which is what keeps us in PCI SAQ-A.
     *
     * @return array{client_secret:string, payment_intent:string, quote:array<string,mixed>}
     */
    public function place(VendorLead $lead): array
    {
        // The checkout already refuses, but no charge may be built for an
        // address that neither FedEx nor the buyer has confirmed.
        if (! $lead->addressReadyForPayment()) {
            throw new RuntimeException('The delivery address has not been verified by FedEx or confirmed by the buyer.');
        }

        $quote = $this->quote($lead);

        if (! $quote['quotable']) {
            throw new RuntimeException($quote['reason'] ?? 'This order cannot be quoted automatically.');
        }

        $client  = $this->stripe->for($lead->vendor);
        $product = Vendors::product($lead->vendor, $lead->product_key);

        /*
         * A Customer on the vendor's account, not just an email on the charge.
         * Their fulfilment team works from their own Stripe dashboard, and a
         * named customer with an address is the difference between an order they
         * can ship and one they have to ring us about.
         */
        $customerParams = [
            'name'    => $lead->fullName(),
            'email'   => $lead->email,
            'phone'   => $lead->phone,
            'address' => $this->destination($lead),
            'shipping' => [
                'name'    => $lead->fullName(),
                'phone'   => $lead->phone,
                'address' => $this->destination($lead),
            ],
            'metadata' => $this->metadata($lead, $quote),
        ];

        $customer = $client->customers->create($customerParams, [
            // Keyed on the parameters, so retrying the same checkout reuses the
            // customer instead of littering the vendor's dashboard with copies —
            // and so the intent below sees the same customer id on a retry.
            'idempotency_key' => $this->idempotencyKey('cus', $lead, $customerParams),
        ]);

        $intentParams = [
            'amount'   => $quote['total'],
            'currency' => strtolower($product['currency'] ?? 'usd'),
            'customer' => $customer->id,

            // Stripe emails the customer their receipt directly from the
            // vendor's account, so the vendor is the one they hear from.
            'receipt_email' => $lead->email,

            'automatic_payment_methods' => ['enabled' => true],

            /*
             * The shipping destination on the PaymentIntent itself, which is
             * where the vendor's order processing reads it from. Duplicated onto
             * the customer above on purpose — whichever object their system
             * pulls, the address is on it.
             */
            'shipping' => [
                'name'    => $lead->fullName(),
                'phone'   => $lead->phone,
                'address' => $this->destination($lead),
                'carrier' => $quote['shipping_quote']->carrier,
            ],

            'description' => $this->description($lead, $product),
            'metadata'    => $this->metadata($lead, $quote),
        ];

        $intent = $client->paymentIntents->create($intentParams, [
            /*
             * A double-clicked Pay button must not create two orders — but the
             * key has to follow the PARAMETERS, not just the reference and total.
             * Stripe refuses a reused key whose parameters differ. The old
             * `ref + total` key therefore broke every retry of the same order
             * (a fresh customer id changed the parameters), and any retry after
             * an address change that left the total untouched.
             */
            'idempotency_key' => $this->idempotencyKey('pi', $lead, $intentParams),
        ]);

        $lead->forceFill([
            'subtotal_amount'      => $quote['subtotal'],
            'shipping_amount'      => $quote['shipping'],
            'handling_amount'      => $quote['handling'],
            'tax_amount'           => $quote['tax'],
            'our_share_amount'     => $quote['our_share'],
            'amount_total'         => $quote['total'],
            'currency'             => strtoupper($product['currency'] ?? 'USD'),
            'provider_payment_intent_id' => $intent->id,
            'tax_calculation_id'   => $quote['tax_calculation'],
            'shipping_rate_source' => $quote['shipping_quote']->source,
            'shipping_service'     => $quote['shipping_quote']->service,
            'carrier'              => $quote['shipping_quote']->carrier,
            'status'               => VendorLead::STATUS_HANDED_OFF,
            'handed_off_at'        => now(),
        ])->save();

        return [
            'client_secret'  => $intent->client_secret,
            'payment_intent' => $intent->id,
            'quote'          => $quote,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Everything the vendor needs to fulfil, written onto the Stripe objects.
     *
     * Their processing system pulls from Stripe, so an order they cannot ship
     * without ringing us is a failure of this method. Keys are prefixed by
     * concern rather than by us: `ship_`, `item_`, `amt_` read as an order to
     * their staff, where `ql_everything` would read as our internal noise.
     *
     * Stripe allows 50 keys, 40 chars per key, 500 per value.
     *
     * @param  array<string,mixed>  $quote
     * @return array<string,string>
     */
    private function metadata(VendorLead $lead, array $quote): array
    {
        $product = Vendors::product($lead->vendor, $lead->product_key) ?? [];

        return array_filter([
            'order_reference'  => $lead->public_ref,
            'order_source'     => 'Quantum 3 partner order',

            'item_sku'         => $lead->product_key,
            'item_name'        => (string) ($product['name'] ?? $lead->product_key),
            'item_quantity'    => (string) $lead->quantity,
            'item_contents'    => (string) ($product['parcel']['contents'] ?? ''),

            'ship_cartons'     => (string) $quote['cartons'],
            'ship_carton_size' => $this->cartonLabel($product),
            'ship_method'      => $quote['shipping_quote']->describe(),
            'ship_name'        => $lead->fullName(),
            'ship_phone'       => (string) $lead->phone,
            'ship_company'     => (string) $lead->company,

            // Whether FedEx confirmed where this is going, so their team knows
            // which orders to double-check before they ship.
            'ship_address_check' => $lead->addressCheckLabel()[0],
            'ship_address_type'  => (string) $lead->address_classification,

            'amt_subtotal'     => $this->money($quote['subtotal']),
            'amt_shipping'     => $this->money($quote['shipping']),
            'amt_handling'     => $this->money($quote['handling']),
            'amt_tax'          => $this->money($quote['tax']),

            // The commercial term, stated on their own record so an invoice from
            // us is reconcilable against it without a phone call.
            'q3_owed_to_partner_co' => $this->money($quote['our_share']),
            'q3_referred_by'   => (string) ($lead->member->name ?? ''),
            'q3_referral_code' => (string) $lead->referral_code,
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    private function description(VendorLead $lead, array $product): string
    {
        return sprintf(
            '%s x%d — order %s (Quantum 3 partner referral)',
            $product['name'] ?? $lead->product_key,
            $lead->quantity,
            $lead->public_ref,
        );
    }

    private function cartonLabel(array $product): string
    {
        $p = $product['parcel'] ?? [];

        if (! isset($p['length_in'])) {
            return '';
        }

        return sprintf('%dx%dx%d in, %d lb each', $p['length_in'], $p['width_in'], $p['height_in'], $p['weight_lb'] ?? 0);
    }

    /** @return array<string,string|null> */
    private function destination(VendorLead $lead): array
    {
        return [
            'line1'       => $lead->address_line1,
            'line2'       => $lead->address_line2,
            'city'        => $lead->city,
            'state'       => $lead->state,
            'postal_code' => $lead->postal_code,
            'country'     => $lead->country ?: 'US',
        ];
    }

    /** The $12 processing fee, per order or per unit depending on config. */
    private function handling(array $vendor, int $qty): int
    {
        $handling = $vendor['pricing']['handling'] ?? [];
        $amount   = (int) ($handling['amount'] ?? 0);

        return ($handling['basis'] ?? 'order') === 'unit' ? $amount * $qty : $amount;
    }

    /**
     * Tax, computed by the VENDOR's Stripe Tax against THEIR registrations.
     *
     * Never our arithmetic. It is their liability, their nexus and their
     * registrations, and a number of ours sitting beside theirs would only ever
     * be a second opinion nobody should act on.
     *
     * A failure here returns zero and logs rather than throwing: tax that cannot
     * be calculated must not take the checkout down, and the confirmed charge is
     * what gets recorded regardless.
     *
     * @return array{0:int, 1:?string}
     */
    private function tax(VendorLead $lead, int $subtotal, int $delivery): array
    {
        if (! (Vendors::find($lead->vendor)['stripe']['automatic_tax'] ?? false)) {
            return [0, null];
        }

        if (blank($lead->postal_code) || blank($lead->country)) {
            return [0, null];   // No address yet, so nothing to calculate against.
        }

        try {
            $calculation = $this->stripe->for($lead->vendor)->tax->calculations->create([
                'currency'   => 'usd',
                'line_items' => [[
                    'amount'        => $subtotal,
                    'reference'     => $lead->product_key,
                    'quantity'      => max(1, (int) $lead->quantity),
                    'tax_behavior'  => 'exclusive',
                ]],
                'shipping_cost' => ['amount' => $delivery, 'tax_behavior' => 'exclusive'],
                'customer_details' => [
                    'address'        => $this->destination($lead),
                    'address_source' => 'shipping',
                ],
            ]);

            return [(int) $calculation->tax_amount_exclusive, $calculation->id];
        } catch (\Throwable $e) {
            Log::warning('Vendor tax calculation failed; proceeding untaxed', [
                'lead'   => $lead->public_ref,
                'vendor' => $lead->vendor,
                'error'  => $e->getMessage(),
            ]);

            return [0, null];
        }
    }

    /**
     * An idempotency key derived from the request parameters.
     *
     * Same approach as StripeClientFactory::idempotencyKey(), repeated here
     * because this client is deliberately separate from that one. Stripe
     * remembers a key for 24 hours and refuses it with different parameters, so
     * a key that ignores the parameters wedges a checkout the moment anything
     * about the order changes.
     *
     * @param  array<string,mixed>  $params
     */
    private function idempotencyKey(string $scope, VendorLead $lead, array $params): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);

            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $normalize($v);
                }
            }

            return $value;
        };

        return sprintf(
            'qlv_%s_%s_%s',
            $scope,
            $lead->public_ref,
            substr(hash('sha256', (string) json_encode($normalize($params))), 0, 24),
        );
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
