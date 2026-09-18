<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Members running their own showings for their own team.
     *
     * A showing is either the company's — every member gets their own link to
     * it — or one member's, in which case only they can see it and only their
     * link exists. `owner_user_id` is what separates them, and null means the
     * company's, matching what already exists.
     *
     * Which recordings a member may schedule is an admin decision: the library
     * holds internal training and half-finished captures as well as the
     * opportunity talk, and none of that should be one click from a prospect.
     */
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->index(['owner_user_id', 'scheduled_at']);
        });

        Schema::table('screen_recordings', function (Blueprint $table) {
            // Off by default: opening the whole library to members by accident
            // is the kind of mistake that only shows up in front of a prospect.
            $table->boolean('member_schedulable')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });

        Schema::table('screen_recordings', function (Blueprint $table) {
            $table->dropColumn('member_schedulable');
        });
    }
};
