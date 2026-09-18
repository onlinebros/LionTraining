<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The running order of a combined recording.
     *
     * A composition is itself an ordinary screen_recordings row — it publishes,
     * gets a visibility, goes into a lesson and can be trimmed like any other.
     * What makes it a composition is that it has clips, and that its file is
     * rendered from them rather than uploaded by a browser.
     */
    public function up(): void
    {
        Schema::create('screen_recording_clips', function (Blueprint $table) {
            $table->id();

            $table->foreignId('composition_id')
                ->constrained('screen_recordings')
                ->cascadeOnDelete();

            // The recording this clip plays. Null once the source is deleted:
            // the rendered file still exists and stays playable, so the clip
            // row is kept as a record of what went into it rather than
            // cascading a deletion into a finished video.
            $table->foreignId('source_recording_id')
                ->nullable()
                ->constrained('screen_recordings')
                ->nullOnDelete();

            // What the source was called when it was added, so the sequence
            // still reads sensibly after a source is renamed or removed.
            $table->string('source_title')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['composition_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_recording_clips');
    }
};
