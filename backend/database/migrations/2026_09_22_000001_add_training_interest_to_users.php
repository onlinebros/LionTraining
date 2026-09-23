<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tell me when the training program opens."
 *
 * The training program is not on sale yet (config/opportunities.php,
 * `enrollment_open`), so its section in the back office is an announcement
 * rather than a checkout. This is the one thing a partner can actually do
 * there, and it is the list to mail on the day it opens.
 *
 * A timestamp rather than a boolean: "when did they ask" answers questions a
 * flag cannot — how interest built, and whether somebody asked before or after
 * a particular announcement. Null means they have not asked.
 *
 * Deliberately not an opportunity association. Holding a line grants its
 * features; wanting one does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('training_interest_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('training_interest_at');
        });
    }
};
