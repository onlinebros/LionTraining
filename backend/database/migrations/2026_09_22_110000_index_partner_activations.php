<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The index the activations report stands on.
 *
 * That screen asks for one partner company's positions that somebody has
 * actually claimed — a few thousand rows out of iHub's 1,304,352. The only
 * index leading with `partner_company_id` is the unique one on
 * (partner_company_id, external_user_id), and reading it for iHub means walking
 * every one of those 1.3 million entries to find the handful that are not
 * holding. That is the whole report, on every page load and every sort change.
 *
 * Partial on `account_status <> 'holding'`, so the index holds claimed and
 * merged positions and nothing else: it stays in the low thousands while the
 * table it covers is in the millions, and an unclaimed spot — which is most
 * writes an import makes — never touches it.
 *
 * `claimed_at DESC` second so the "most recently activated" ordering comes off
 * the index too. The sales orderings cannot use it, but by then the set is
 * small enough to sort in memory; it is finding the rows that was expensive.
 *
 * CONCURRENTLY, and therefore outside a transaction, for the reason
 * 2026_09_21_000001 gives: an ordinary CREATE INDEX holds a write lock on
 * `users` for as long as it takes to read the table, and `users` is live.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS users_partner_activated_idx
                 ON users (partner_company_id, claimed_at DESC)
              WHERE partner_company_id IS NOT NULL AND account_status <> 'holding'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_partner_activated_idx');
    }
};
