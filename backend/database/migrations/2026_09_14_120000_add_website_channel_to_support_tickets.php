<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a support ticket come from the public website contact form, where the
 * requester has no account.
 *
 * Such a ticket (and its first message) has no user, so user_id becomes
 * nullable on both tables. The foreign keys stay: a non-null user_id must
 * still point at a real user. Who asked is recorded in requester_name /
 * requester_email instead, and `channel` says where the ticket came from.
 *
 * Laravel 11+ has no DBAL: ->change() rewrites the whole column definition, so
 * the type is restated along with the new nullability. The FK constraints are
 * separate objects in Postgres and are not touched by ALTER COLUMN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();

            $table->string('channel', 20)->default('member');
            $table->string('requester_name', 120)->nullable();
            $table->string('requester_email', 255)->nullable();

            $table->index('channel');
        });

        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * DESTRUCTIVE: NOT NULL cannot be restored while userless rows exist, so
     * rolling back deletes every website ticket (its replies cascade) and any
     * other userless ticket or reply. Export them first if they matter.
     */
    public function down(): void
    {
        DB::table('support_tickets')
            ->where('channel', 'website')
            ->orWhereNull('user_id')
            ->delete();

        DB::table('support_ticket_replies')->whereNull('user_id')->delete();

        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'requester_name', 'requester_email']);

            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
