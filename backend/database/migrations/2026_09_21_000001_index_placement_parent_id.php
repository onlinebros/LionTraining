<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first-level lookup that everything about a partner's own team rests on.
 *
 * `placement_parent_id` had no index. Two comments in GenealogyService describe
 * their query as "an indexed lookup on placement_parent_id" — the index was
 * simply never created, and nothing said so while the table was small.
 *
 * Once the iHub import put 1.3 million positions in `users` it stopped being
 * free: directSpotCounts(), which My Team and Your Spots both call on every
 * load, became a parallel sequential scan reading the whole table to count a
 * partner's forty direct spots. 14.3 seconds measured on production, which is
 * most of the way to Cloudflare hanging up on the member looking at it.
 *
 * A plain btree rather than a partial one: `directs()` reads the same column
 * without the `partner_company_id IS NOT NULL` filter, so both want it whole.
 *
 * CONCURRENTLY, and therefore outside a transaction: the table is live, and an
 * ordinary CREATE INDEX holds a write lock for as long as it takes to read a
 * million rows.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS users_placement_parent_id_idx
                 ON users (placement_parent_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_placement_parent_id_idx');
    }
};
