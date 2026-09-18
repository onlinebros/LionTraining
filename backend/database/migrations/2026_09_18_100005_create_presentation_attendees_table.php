<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest who registered for one showing.
     *
     * This is the record the inviting member keeps. It outlives the
     * presentation and is the thing they export, so nothing here cascades away
     * when a showing is tidied up.
     */
    public function up(): void
    {
        Schema::create('presentation_attendees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('presentation_id')
                ->constrained('presentations')
                ->cascadeOnDelete();

            /*
             * The member who invited them. NULL means they arrived on the plain
             * company link with no referral code — those are admin-only.
             *
             * This column is the isolation boundary: every visibility rule in
             * the feature reduces to a comparison against it.
             */
            $table->foreignId('host_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();

            // Identifies the guest's session in a cookie. Unguessable because
            // it is the only credential they have.
            $table->string('token', 64)->unique();

            $table->timestamp('registered_at');
            $table->timestamp('first_joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Seconds into the presentation when they arrived — the number that
            // actually differs between guests, since everyone is synced.
            $table->unsignedInteger('joined_at_offset')->nullable();

            // Accumulated from the gaps between heartbeats, never taken from
            // whatever the client claims.
            $table->unsignedInteger('watch_seconds')->default(0);
            $table->unsignedInteger('position_seconds')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();

            // Set when they click the call to action, so a member can see who
            // acted on the invitation.
            $table->timestamp('cta_clicked_at')->nullable();

            $table->timestamps();

            // One registration per person per showing — the guard that makes
            // the first-inviter-wins rule meaningful.
            $table->unique(['presentation_id', 'email']);
            $table->index(['presentation_id', 'host_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_attendees');
    }
};
