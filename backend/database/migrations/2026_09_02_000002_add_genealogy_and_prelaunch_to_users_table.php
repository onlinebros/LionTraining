<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The genealogy and the pre-launch guard's per-user flag.
 *
 * Two trees, deliberately separate columns even though Quantum Life runs a
 * unilevel where they are always identical:
 *
 *   sponsor_id / enrollment_path  — who recruited whom. Never changes.
 *   placement_parent_id / placement_path — where someone sits in the structure
 *                                          that pays. In a unilevel this is a
 *                                          mirror of the enrollment tree.
 *
 * Keeping them apart costs two nullable columns now and is what allows a later
 * switch to binary or matrix to be a config change rather than a migration —
 * in those structures the trees genuinely diverge, because spillover places
 * someone under a person who did not recruit them. Collapsing them into one
 * column today would make that switch a data migration on a live genealogy,
 * which is the single worst migration in this category of system.
 *
 * See docs/quantum-life-solutions/00-decisions.md § D1 in the reference repo.
 */
return new class extends Migration
{
    /** Path columns are `ltree` on Postgres and fall back to a string elsewhere. */
    private function pathType(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ltree' : 'varchar(1000)';
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ── Enrollment tree ───────────────────────────────────────────────
            $table->foreignId('sponsor_id')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();

            // ── Placement tree ────────────────────────────────────────────────
            $table->foreignId('placement_parent_id')->nullable()->after('sponsor_id')
                ->constrained('users')->nullOnDelete();

            // queued → placed. 'excluded' is for accounts that must never enter
            // the structure at all: staff, test accounts, the company node.
            $table->string('placement_status', 16)->default('queued')->after('placement_parent_id');
            $table->timestamp('placement_queued_at')->nullable()->after('placement_status');
            $table->timestamp('placed_at')->nullable()->after('placement_queued_at');

            // ── Pre-launch preview ────────────────────────────────────────────
            // Lets named accounts through the guard. Admins pass automatically,
            // so this is for the founding team and the support pilot group.
            $table->boolean('prelaunch_preview')->default(false)->after('placed_at');

            $table->index('placement_status');
            $table->index('sponsor_id', 'users_sponsor_id_idx');
        });

        // ltree columns and their GIST indexes. Added with raw DDL because
        // Laravel's schema grammar has no ltree type, and because GIST is the
        // index ltree's ancestor/descendant operators (@>, <@) actually use —
        // a btree index on these columns would be dead weight.
        $type = $this->pathType();

        DB::statement("ALTER TABLE users ADD COLUMN enrollment_path {$type}");
        DB::statement("ALTER TABLE users ADD COLUMN placement_path {$type}");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX users_enrollment_path_gist ON users USING GIST (enrollment_path)');
            DB::statement('CREATE INDEX users_placement_path_gist ON users USING GIST (placement_path)');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->index('enrollment_path');
                $table->index('placement_path');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_enrollment_path_gist');
            DB::statement('DROP INDEX IF EXISTS users_placement_path_gist');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_id');
            $table->dropConstrainedForeignId('placement_parent_id');
            $table->dropColumn([
                'placement_status',
                'placement_queued_at',
                'placed_at',
                'prelaunch_preview',
                'enrollment_path',
                'placement_path',
            ]);
        });
    }
};
