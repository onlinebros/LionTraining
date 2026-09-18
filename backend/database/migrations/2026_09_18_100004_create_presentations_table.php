<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled showing of a recording that a group watches together.
     *
     * One recording can back many presentations — the same opportunity talk run
     * Tuesday morning and Thursday evening — so this is deliberately a separate
     * row per showing rather than a repeat rule. Attendance, attribution and
     * conversation all belong to a specific occurrence.
     */
    public function up(): void
    {
        Schema::create('presentations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // The shareable part of the URL: /watch/{slug}/{referral_code}
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            // What plays. Restricted rather than cascaded: deleting a recording
            // that a scheduled showing depends on must fail loudly.
            $table->foreignId('recording_id')
                ->constrained('screen_recordings')
                ->restrictOnDelete();

            $table->timestamp('scheduled_at');

            /*
             * started_at is the entire synchronisation mechanism — every
             * viewer's position is (now - started_at). It is written once and
             * never moved, because changing it would yank every viewer to a
             * different point at once.
             */
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Copied from the recording when scheduled, so a later trim of the
            // source cannot silently change when this showing ends.
            $table->unsignedInteger('duration_seconds');

            $table->string('status')->default('scheduled'); // scheduled|live|ended|cancelled

            // none | attendees | members | link
            $table->string('replay_visibility')->default('none');

            $table->boolean('collect_phone')->default(false);
            $table->boolean('is_open')->default(true); // accepting registrations

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentations');
    }
};
