<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telling a partner company when one of their positions gets claimed.
 *
 * The import is one-way and carries no personal data. This is the return leg,
 * and it is the opposite shape: the member has an account with us now, and the
 * partner wants to know which of their people came across.
 *
 * That makes `webhook_include_contact` the important column here. Sending an
 * external_user_id and a timestamp back tells the partner what they need to
 * reconcile their own list. Sending the member's name, email and phone is a
 * disclosure of somebody's personal data to a third party, and it is off unless
 * switched on per company — so that turning it on is a decision somebody makes,
 * with the claim page's wording updated to match, rather than a default nobody
 * chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->string('webhook_url')->nullable()->after('support_email');

            // Encrypted at rest. It is the shared secret that lets the partner
            // prove a delivery came from us; a leaked one lets anybody forge
            // "this spot was claimed" into their system.
            $table->text('webhook_secret')->nullable()->after('webhook_url');

            $table->boolean('webhook_enabled')->default(false)->after('webhook_secret');

            // See the class note. Off by default, deliberately.
            $table->boolean('webhook_include_contact')->default(false)->after('webhook_enabled');

            // Enough to drive an "is this integration healthy" line on the admin
            // screen without aggregating the delivery table on every page load.
            $table->timestamp('webhook_last_success_at')->nullable()->after('webhook_include_contact');
            $table->unsignedInteger('webhook_consecutive_failures')->default(0)->after('webhook_last_success_at');
        });
    }

    public function down(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_url',
                'webhook_secret',
                'webhook_enabled',
                'webhook_include_contact',
                'webhook_last_success_at',
                'webhook_consecutive_failures',
            ]);
        });
    }
};
