<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The index the commit joins on.
 *
 * Every statement in SpotImportCommitter after the initial INSERT finds the
 * rows it just created by (partner_import_id, external_user_id). There was no
 * index on that pair: the only index covering external_user_id is the unique
 * one on (partner_company_id, external_user_id), which Postgres cannot use when
 * the query constrains the import rather than the company.
 *
 * At forty rows nobody notices. At 1.3 million it is the difference between the
 * parent-resolution pass taking seconds and taking long enough to make you
 * check whether it has hung.
 *
 * Partial, on `partner_import_id IS NOT NULL`, so it indexes imported positions
 * and nothing else. Ordinary signups are the overwhelming majority of this table
 * and none of them ever match; making them carry the write cost of an index
 * that can never find them would be paying for the import forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS users_partner_import_lookup_idx
                 ON users (partner_import_id, external_user_id)
              WHERE partner_import_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_partner_import_lookup_idx');
    }
};
