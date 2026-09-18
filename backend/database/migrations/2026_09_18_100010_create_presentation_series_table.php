<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repeating schedule for one recording — "Tuesdays and Thursdays at 7pm".
     *
     * The series is only a rule. Each occurrence is still its own presentation
     * row, generated ahead of time, because attendance, conversation and
     * attribution all belong to a specific showing. Modelling it as a repeat
     * flag on one row would mean every Tuesday's guests piling into the same
     * inbox.
     */
    public function up(): void
    {
        Schema::create('presentation_series', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recording_id')
                ->constrained('screen_recordings')
                ->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // 0 = Sunday … 6 = Saturday, and "19:00" style clock times, both
            // read in the booking timezone (Eastern).
            $table->json('days');
            $table->json('times');

            // How far ahead occurrences are materialised. Far enough that
            // members can share a link for next week, short enough that a
            // change to the series takes effect soon.
            $table->unsignedSmallInteger('weeks_ahead')->default(4);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->string('replay_visibility')->default('none');
            $table->boolean('collect_phone')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('presentations', function (Blueprint $table) {
            // Null for a one-off. Set null rather than cascading if the series
            // is deleted: the showings that already happened keep their
            // attendance and their conversations.
            $table->foreignId('series_id')
                ->nullable()
                ->after('recording_id')
                ->constrained('presentation_series')
                ->nullOnDelete();

            $table->index(['series_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
        });

        Schema::dropIfExists('presentation_series');
    }
};
