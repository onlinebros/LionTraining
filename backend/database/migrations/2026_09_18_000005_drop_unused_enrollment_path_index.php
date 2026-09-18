<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the GIST index on `enrollment_path`.
 *
 * It costs 1,935 MB and, according to pg_stat_user_indexes on production, has
 * been scanned exactly once in its life. The placement index next to it has 51
 * scans; the primary key has 24 million. The only method that would use it,
 * GenealogyService::enrollmentDescendants(), is defined and called by nothing —
 * not the application, not a view, not a test.
 *
 * The column stays. The enrollment tree is a real concept, the sponsor data in
 * it is correct, and it is maintained on every write. What goes is the index
 * nothing reads.
 *
 * ── Why now ──────────────────────────────────────────────────────────────────
 *
 * Merging a founder's imported position rewrites `enrollment_path` for every
 * row beneath it, and each rewrite has to update this index. On the 1,263,728
 * positions under iHub-4 that statement alone ran past forty minutes — roughly
 * half the merge — to maintain an index that has answered one query.
 *
 * Two more merges of that shape are queued behind it. Dropping this first is
 * the difference between those taking an afternoon and taking minutes.
 *
 * If something ever genuinely needs to walk the enrollment tree by range, the
 * index comes back with one statement and an hour of build time. Carrying it on
 * the chance is what has been expensive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CONCURRENTLY so it does not take an exclusive lock on a table the
        // application is serving from. It cannot run inside a transaction,
        // which is what withinTransaction(false) is for.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_enrollment_path_gist');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS users_enrollment_path_gist
                 ON users USING GIST (enrollment_path)'
        );
    }

    /**
     * Postgres refuses CONCURRENTLY inside a transaction block, and Laravel
     * wraps migrations in one by default.
     */
    public $withinTransaction = false;
};
