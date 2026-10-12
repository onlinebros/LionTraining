<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests are kept on the live site; dev collects the turns waiting for the
 * assistant, answers them on its own CLI, and reviews the requests from there.
 * A reviewer on dev has no account on live, so reviews and comments carry the
 * reviewer's name rather than a user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only dev ever had this (per-person access, replaced by super admins
        // only before anything was deployed).
        if (Schema::hasColumn('users', 'can_request_changes')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_request_changes');
            });
        }

        Schema::table('change_requests', function (Blueprint $table) {
            // reply | plan: the turn waiting for dev. Cleared when it is answered.
            $table->string('assistant_pending', 8)->nullable()->index();
            // When dev took the turn, so it is handed out once.
            $table->timestamp('assistant_claimed_at')->nullable();
            $table->string('reviewer_name')->nullable();
        });

        Schema::table('change_request_messages', function (Blueprint $table) {
            $table->string('author_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('change_request_messages', function (Blueprint $table) {
            $table->dropColumn('author_name');
        });
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn(['assistant_pending', 'assistant_claimed_at', 'reviewer_name']);
        });
    }
};
