<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding spots: positions that exist in the structure before anyone owns them.
 *
 * An imported spot is a real `users` row from the moment the batch commits —
 * same table, same `placement_path`, same ltree index as everybody else. That
 * is what makes a holding spot able to sit between two activated partners, and
 * what makes claiming one a matter of filling in the blanks rather than moving
 * a position. The alternative, a separate table materialised on claim, splits
 * every ancestor query across two shapes of row and makes the path arithmetic
 * for a mixed leg something nobody wants to debug at 2am.
 *
 * The cost is that `users` now contains rows that are not people yet, and every
 * screen that counts partners has to say so. That cost is paid once, in
 * User::scopeActivated() and in GenealogyService, rather than being spread
 * across the application — see those two for the rule.
 *
 * Two columns become nullable here. A holding spot has no email until somebody
 * claims it (its `email` is what the partner company had on file, which may be
 * stale, and must not occupy our unique index as if it were a login), and no
 * password at all. Postgres permits any number of NULLs under a unique index,
 * so unclaimed spots do not collide with each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'active' is every account that exists today and every account
            // created by normal signup. Defaulting the other way would turn the
            // entire live membership into unclaimed spots on deploy.
            $table->string('account_status', 16)->default('active')->after('is_active');

            // ── Provenance ───────────────────────────────────────────────────
            $table->foreignId('partner_company_id')->nullable()->after('account_status')
                ->constrained()->nullOnDelete();
            $table->foreignId('partner_import_id')->nullable()->after('partner_company_id')
                ->constrained()->nullOnDelete();

            // The identifier the partner company knows this person by. Kept for
            // life, not just until claim: it is how their support desk and ours
            // talk about the same person.
            $table->string('external_user_id', 64)->nullable()->after('partner_import_id');

            // The activation code, hashed. Never stored in plaintext on a live
            // row — it is a credential that lets somebody take ownership of a
            // position, so a leaked database must not hand over the list.
            $table->string('activation_code_hash')->nullable()->after('external_user_id');

            // Set to null when the spot is claimed, so a code cannot be
            // replayed to re-claim a position that now has an owner.
            $table->timestamp('claimed_at')->nullable()->after('activation_code_hash');
            $table->timestamp('imported_at')->nullable()->after('claimed_at');

            // Failed code attempts against this spot. Throttling by IP alone is
            // not enough when the attacker has the whole list of user ids and
            // only needs one code; this is the per-spot ceiling.
            $table->unsignedSmallInteger('claim_attempts')->default(0)->after('imported_at');
            $table->timestamp('claim_locked_until')->nullable()->after('claim_attempts');

            $table->index('account_status');

            // The pair is unique, the identifier alone is not: two partner
            // companies will each have a member 1001.
            $table->unique(['partner_company_id', 'external_user_id'], 'users_partner_external_id_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_partner_external_id_unique');
            $table->dropIndex(['account_status']);
            $table->dropConstrainedForeignId('partner_company_id');
            $table->dropConstrainedForeignId('partner_import_id');
            $table->dropColumn([
                'account_status',
                'external_user_id',
                'activation_code_hash',
                'claimed_at',
                'imported_at',
                'claim_attempts',
                'claim_locked_until',
            ]);
        });

        // Only reversible while no holding spot is left standing; a NOT NULL
        // constraint cannot be restored over rows that are null by design.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
