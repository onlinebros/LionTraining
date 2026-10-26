<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The country a partner's payout account is opened in (CR-0003, TKT-00004).
 *
 * Stripe fixes an account's country at creation, so the partner chooses it on
 * Get Paid before the account exists. The free-text profile country is only a
 * suggestion. After the account exists, every sync rewrites this from Stripe.
 *
 * A nullable column with no default and no index: on Postgres this changes
 * only the catalogue, so it is safe on the 1.3M-row live users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('connect_country', 2)->nullable()->after('stripe_connect_account_id');
        });

        // Every account opened before this was a US one. Only partners with an
        // account, found through the unique index on the account id.
        DB::table('users')->whereNotNull('stripe_connect_account_id')->update(['connect_country' => 'US']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('connect_country');
        });
    }
};
