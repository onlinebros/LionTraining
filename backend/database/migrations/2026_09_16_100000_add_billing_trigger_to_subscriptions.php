<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which enrollment option a subscription was opened under.
 *
 * `launch`     — "Recover my genius now": card on file, first charge the day
 *                the training program opens.
 * `commission` — "Wait for my commissions": card on file, held until the
 *                partner's paid commissions reach the threshold.
 *
 * Every existing row was opened under the launch schedule, so the default is
 * the backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_trigger', 20)->default('launch')->after('is_prelaunch_trial')->index();
            $table->timestamp('billing_trigger_met_at')->nullable()->after('billing_trigger');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['billing_trigger']);
            $table->dropColumn(['billing_trigger', 'billing_trigger_met_at']);
        });
    }
};
