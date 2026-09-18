<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counting pending sponsorships without reading a million active ones.
 *
 * The admin dashboard — the page an admin lands on immediately after logging
 * in — counts sponsorships awaiting acceptance. There is no index on `status`,
 * so it was a sequential scan: 2.1 seconds once the iHub import put 1.3 million
 * rows in that table, every single time the dashboard loaded, to return zero.
 *
 * A partial index is the right shape here rather than an index on `status`.
 * Sponsorships are written as 'active' and effectively never anything else —
 * see EnrollmentService, which records them accepted because a pending
 * sponsorship would leave a partner outside the tree. So an index covering the
 * whole column would be a million entries to find nothing, while this one holds
 * only the rows that are actually pending and is close to empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX IF NOT EXISTS sponsorships_pending_idx
                 ON sponsorships (status)
              WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sponsorships_pending_idx');
    }
};
