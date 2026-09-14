<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Connect payouts: the account each partner is paid into, and the
 * transfer that pays a commission payout into it.
 *
 * The connected account's state is mirrored onto the user so the back office
 * can answer "who can we pay" without an API call per partner. Stripe stays the
 * source of truth: StripeConnectService rewrites these columns on every sync and
 * every `account.updated` webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Unique so one connected account can never back two partners.
            $table->string('stripe_connect_account_id')->nullable()->unique()->after('billing_exempt');
            $table->boolean('connect_charges_enabled')->default(false)->after('stripe_connect_account_id');
            $table->boolean('connect_payouts_enabled')->default(false)->after('connect_charges_enabled');
            $table->boolean('connect_details_submitted')->default(false)->after('connect_payouts_enabled');
            // Stripe's status for tax_reporting_us_1099_misc: active, pending,
            // inactive or unrequested. Null until the account is first synced.
            $table->string('connect_tax_reporting_status', 32)->nullable()->after('connect_details_submitted');
            $table->json('connect_requirements')->nullable()->after('connect_tax_reporting_status');
            $table->timestamp('connect_synced_at')->nullable()->after('connect_requirements');
        });

        // One real bank account, one partner. Stripe never exposes account
        // numbers, but an external account's fingerprint is stable per real
        // account across the platform. Stored as a keyed hash only.
        Schema::create('connect_identity_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // bank_account | individual
            $table->string('claim_type', 32)->index();
            $table->string('claim_hash', 64)->index();
            // Mirrors claim_hash only for claims that are enforced.
            $table->string('unique_hash', 64)->nullable()->unique();
            // The Stripe object the claim came from (ba_…, acct_…), for support.
            $table->string('source_ref')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'claim_type', 'claim_hash'], 'connect_claims_user_type_hash_unique');
        });

        Schema::table('commission_payouts', function (Blueprint $table) {
            // Unique so a double-clicked "Pay via Stripe" can never send twice,
            // on top of the idempotency key.
            $table->string('stripe_transfer_id')->nullable()->unique()->after('payment_reference');
            $table->string('stripe_destination_account')->nullable()->after('stripe_transfer_id');
            // null (never sent) | paid | failed | reversed
            $table->string('transfer_status', 16)->nullable()->index()->after('stripe_destination_account');
            $table->text('transfer_failure_reason')->nullable()->after('transfer_status');
            $table->timestamp('transferred_at')->nullable()->after('transfer_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_transfer_id',
                'stripe_destination_account',
                'transfer_status',
                'transfer_failure_reason',
                'transferred_at',
            ]);
        });

        Schema::dropIfExists('connect_identity_claims');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_connect_account_id',
                'connect_charges_enabled',
                'connect_payouts_enabled',
                'connect_details_submitted',
                'connect_tax_reporting_status',
                'connect_requirements',
                'connect_synced_at',
            ]);
        });
    }
};
