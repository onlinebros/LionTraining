<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A one-way message from the stage to everyone watching.
     *
     * Separate from presentation_messages on purpose: an announcement has no
     * thread and no recipient, so giving it a nullable attendee_id in the
     * messages table would put a row there that every visibility rule has to
     * special-case. A guest replying to an announcement replies into their own
     * private thread.
     */
    public function up(): void
    {
        Schema::create('presentation_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['presentation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_announcements');
    }
};
