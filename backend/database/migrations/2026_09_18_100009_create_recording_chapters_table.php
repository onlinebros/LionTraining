<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named cue points in a recording — "The Compensation Plan" at 14:02.
     *
     * Attached to the RECORDING, not to a showing: the same talk scheduled ten
     * times should have its chapters typed once. They turn "she is 14 minutes
     * in" into something a member can actually act on.
     */
    public function up(): void
    {
        Schema::create('recording_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained('screen_recordings')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('starts_at_seconds');
            $table->timestamps();

            $table->index(['recording_id', 'starts_at_seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_chapters');
    }
};
