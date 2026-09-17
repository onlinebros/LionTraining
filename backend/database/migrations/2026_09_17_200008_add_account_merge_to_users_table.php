<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folding one position into another.
 *
 * The case this exists for: a Quantum founder already has a position here, and
 * also has an iHub position with an organisation under it. Both are theirs, and
 * they want one account with one team — the founder position, carrying the iHub
 * downline.
 *
 * Note what this is and is not. It does **not** move a position: the imported
 * spot's own place in the tree is not relocated, it is retired, and the people
 * who were beneath it are re-hung under the account that absorbed it. Positions
 * still never move. What moves is a subtree, on purpose, by an administrator,
 * with both sides belonging to the same person.
 *
 * A merged row is kept rather than deleted. It carries an external_user_id that
 * iHub will quote at us for years, it is referenced by sponsorships and ledger
 * rows, and "that id was merged into account 40 on this date" is the only
 * answer to the support call that eventually comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set on the row that was absorbed, pointing at the survivor.
            $table->foreignId('merged_into_user_id')->nullable()->after('claimed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_user_id');
            $table->dropColumn('merged_at');
        });
    }
};
