<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many positions a company has, and how many are still waiting.
 *
 * Kept on the row rather than counted on demand. Counting iHub's positions is
 * `count(*) from users where partner_company_id = 1`, and because that matches
 * 1,304,352 of 1,304,352 rows there is no index that avoids reading all of
 * them: measured at 14.7 seconds on production, and 29 seconds once a few
 * requests arrive at once.
 *
 * It was cached, which does not help. A five-minute cache on a fifteen-second
 * query means every five minutes several requests miss together, all start the
 * same scan, and each takes longer than it would have alone. That is worse than
 * no cache, and it is what the public claim page was doing — the page about to
 * be linked in a mailing to a million people.
 *
 * So the number is maintained where it changes: set when an import commits,
 * decremented when somebody claims. Reading it is free, and it cannot stampede
 * because there is nothing to compute.
 *
 * Denormalised counters drift. `partners:recount` rebuilds them from the rows
 * themselves, and is the answer whenever the number looks wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->unsignedInteger('total_spots')->default(0)->after('is_active');
            $table->unsignedInteger('unclaimed_spots')->default(0)->after('total_spots');
        });
    }

    public function down(): void
    {
        Schema::table('partner_companies', function (Blueprint $table) {
            $table->dropColumn(['total_spots', 'unclaimed_spots']);
        });
    }
};
