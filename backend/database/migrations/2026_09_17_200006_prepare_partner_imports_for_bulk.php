<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a 1.3-million-row import needs that a 40-row one did not.
 *
 * The first real partner list is iHub Global's: 1,304,352 positions, 72 levels
 * deep, 99.99% of it hanging off a single leg. At that size the import stops
 * being a loop over rows and becomes a handful of set-based statements, and
 * this migration is what those statements need.
 *
 *  pgcrypto          hashing a million activation codes inside one
 *                    INSERT ... SELECT instead of a million PHP round trips
 *  placement_depth   computed once at validation, then used to build ltree
 *                    paths one level at a time — 72 UPDATEs instead of 1.3M
 *  enrollment_depth  the same for the sponsor tree, which is a different tree
 *                    and cannot borrow the placement ordering
 *  ...(external_user_id) index
 *                    every join in the commit is staging-to-staging or
 *                    staging-to-users on this column
 */
return new class extends Migration
{
    public function up(): void
    {
        // hmac() and encode() for ActivationCode::sqlExpression(). Ships with
        // Postgres; not enabled by default.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        Schema::table('partner_import_rows', function (Blueprint $table) {
            // Null after validation means "not reachable from any leg top",
            // which is how a cycle shows up once orphans are ruled out. The
            // committer refuses to run while any row is null.
            $table->unsignedSmallInteger('placement_depth')->nullable()->after('parent_user_id');
            $table->unsignedSmallInteger('enrollment_depth')->nullable()->after('placement_depth');

            $table->index(['partner_import_id', 'placement_depth']);
        });

        // The join key for everything the commit does. Not unique on its own —
        // the (import, id) unique index already covers correctness — but the
        // commit joins staging to staging on parent id, and without this each
        // of the 72 level passes is a sequential scan of 1.3M rows.
        DB::statement(
            'CREATE INDEX partner_import_rows_external_sponsor_idx '
            .'ON partner_import_rows (partner_import_id, external_sponsor_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS partner_import_rows_external_sponsor_idx');

        Schema::table('partner_import_rows', function (Blueprint $table) {
            $table->dropIndex(['partner_import_id', 'placement_depth']);
            $table->dropColumn(['placement_depth', 'enrollment_depth']);
        });
    }
};
