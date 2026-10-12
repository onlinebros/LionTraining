<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page a change request was started from, when it was started from the
 * header button rather than the Change Requests screen. Scheme, host and path
 * only; the query string is dropped in case it carries a signed link or token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->string('source_url', 2048)->nullable()->after('title');
            $table->string('source_title', 255)->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'source_title']);
        });
    }
};
