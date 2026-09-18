<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One guest's private conversation.
     *
     * There is deliberately no room, no channel and no group thread. The thread
     * key is `attendee_id`, and a message is readable by the guest, the member
     * who invited them, and admins — nobody else. A room-shaped chat cannot be
     * made private afterwards, only thrown away, so it is not built here even
     * as a convenience.
     */
    public function up(): void
    {
        Schema::create('presentation_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();

            // The thread. Every visibility decision starts from this row's
            // attendee and its host_user_id.
            $table->foreignId('attendee_id')
                ->constrained('presentation_attendees')
                ->cascadeOnDelete();

            // attendee | member | admin
            $table->string('sender_type', 16);

            // Null when the guest is speaking — they have no account.
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            // When the *other side* read it. Drives the unread badge without
            // needing a per-user read table for a two-sided conversation.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['attendee_id', 'id']);
            $table->index(['presentation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_messages');
    }
};
