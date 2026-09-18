<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The losing side of an attribution clash.
     *
     * Two members invite the same person to the same showing. The first to
     * register them keeps them; the second is recorded here rather than
     * discarded. The second member is not told and never sees the guest —
     * telling them would leak that the guest exists, which is the isolation
     * boundary this whole feature is built around.
     *
     * Admins can see these, because "why is this lead not mine" becomes a
     * commission conversation and somebody has to be able to answer it.
     */
    public function up(): void
    {
        Schema::create('presentation_attendee_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained('presentation_attendees')->cascadeOnDelete();

            // The member who tried second.
            $table->foreignId('claimed_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['presentation_id', 'claimed_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_attendee_claims');
    }
};
