<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The genealogy stores materialized paths as Postgres `ltree` values, which
 * makes "everyone below me", "my upline" and "my team at level N" single
 * indexed queries instead of recursive walks.
 *
 * ltree is a *trusted* extension from Postgres 13 onward, so the database
 * owner can create it without superuser rights — which is what CI and any
 * managed-Postgres deployment will be running as.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
    }

    public function down(): void
    {
        // Deliberately not dropped. Other tables' columns depend on the type,
        // and dropping an extension out from under them fails noisily or
        // cascades into data loss. Removing it is a manual, deliberate act.
    }
};
