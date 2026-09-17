<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose signature convention a partner's endpoint expects.
 *
 * We do not get to pick this. A partner has an existing webhook receiver with
 * an existing verification routine, and the integration is measured in days
 * saved by matching it rather than in elegance. iHub's endpoint reads
 * `X-Partner-Signature: sha256=<hmac of the body>`, which is the GitHub
 * convention; ours adds a timestamp to the signed material, which is the Stripe
 * convention and is strictly better against replay.
 *
 * So both, chosen per company, defaulting to ours because it is the one to
 * offer a partner who has no opinion yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->string('webhook_signature_style', 32)
                ->default('q3_timestamped')
                ->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->dropColumn('webhook_signature_style');
        });
    }
};
