<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order, as opposed to the enquiry.
 *
 * Until now a lead recorded who wanted what and, eventually, that money changed
 * hands somewhere else. Building the charge ourselves on the vendor's account
 * means we now own the arithmetic, so every component of it has to be stored —
 * not recomputed later from config that will have moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            /*
             * The quote, broken out. All minor units.
             *
             * Stored rather than derived: the price, the shipping rate and the
             * tax rules all change, and an order has to still explain itself in
             * a year. `amount_total` already exists and remains the vendor's
             * confirmed figure — these are what we computed to reach it.
             */
            $table->unsignedBigInteger('subtotal_amount')->nullable()->after('quantity');
            $table->unsignedBigInteger('shipping_amount')->nullable()->after('subtotal_amount');
            $table->unsignedBigInteger('handling_amount')->nullable()->after('shipping_amount');
            $table->unsignedBigInteger('tax_amount')->nullable()->after('handling_amount');

            /*
             * What the vendor owes us for this order. Recorded at order time
             * from the then-current per-unit figure, because the commercial
             * term will be renegotiated and past orders must not silently
             * reprice when it is.
             */
            $table->unsignedBigInteger('our_share_amount')->nullable()->after('tax_amount');

            /*
             * Collected outside Stripe — we invoice the vendor rather than
             * taking an application fee. Without these two columns "what are we
             * owed" is answerable only by reading every order by hand.
             */
            $table->timestamp('invoiced_at')->nullable()->after('our_share_amount');
            $table->string('invoice_reference', 100)->nullable()->after('invoiced_at');
            $table->timestamp('settled_at')->nullable()->after('invoice_reference');

            // ── The vendor's own record, linked ──────────────────────────────
            $table->string('stripe_charge_id', 191)->nullable()->after('provider_payment_intent_id');

            /*
             * Stripe's hosted receipt for the charge. The customer's proof of
             * purchase, reachable from our order screen so support does not have
             * to ask the vendor for it.
             */
            $table->text('stripe_receipt_url')->nullable()->after('stripe_charge_id');

            // Ties our order to the vendor's Stripe Tax record for the same sale.
            $table->string('tax_calculation_id', 191)->nullable()->after('stripe_receipt_url');

            // How the shipping figure was arrived at — a live carrier rate, a
            // flat fallback, or a human. A quote nobody can explain is a dispute.
            $table->string('shipping_rate_source', 32)->nullable()->after('tax_calculation_id');
            $table->string('shipping_service', 64)->nullable()->after('shipping_rate_source');

            // ── Fulfilment, reported back by the vendor ──────────────────────
            $table->string('carrier', 40)->nullable()->after('shipping_service');
            $table->string('tracking_number', 100)->nullable()->after('carrier');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');

            // "What have we not billed for yet" is the question the finance
            // screen asks on every load.
            $table->index(['status', 'invoiced_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            $table->dropIndex(['status', 'invoiced_at']);
            $table->dropColumn([
                'subtotal_amount', 'shipping_amount', 'handling_amount', 'tax_amount',
                'our_share_amount', 'invoiced_at', 'invoice_reference', 'settled_at',
                'stripe_charge_id', 'stripe_receipt_url', 'tax_calculation_id',
                'shipping_rate_source', 'shipping_service',
                'carrier', 'tracking_number', 'shipped_at',
            ]);
        });
    }
};
