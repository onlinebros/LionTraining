<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funnel presentations: a flow of videos the viewer steers themselves.
     *
     * A single showing asks one question at the end. A funnel asks a question
     * *during* a video — "which of these do you want to see next?" — and the
     * answer either plays another video or sends them somewhere that ends the
     * journey: a report, a sign-up, a booked call.
     *
     * Four ideas, one per table:
     *
     *  - `cta_items` — the things a person can be asked to do, written once and
     *    reused. "Book a call", "Get my report", "Join as a member".
     *  - `presentation_cues` — placing one on a video at a moment. A cue either
     *    offers a `cta_item` or branches to another video; both are choices, so
     *    both live here rather than in two parallel shapes.
     *  - `presentation_funnels` — the flow itself, and where it starts.
     *  - `funnel_participants` — one person moving through it. This is the row
     *    that makes the whole thing hang together: it carries the inviting
     *    member from the first video to the last, so a prospect five videos
     *    deep is still unambiguously theirs.
     *  - `funnel_choice_events` — every choice, kept. What people pick is the
     *    most useful thing this feature produces.
     */
    public function up(): void
    {
        Schema::create('cta_items', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // What an admin calls it in the library, which is not what a guest
            // reads on the button.
            $table->string('name', 120);
            $table->string('kind', 32)->default('join');
            $table->string('headline', 160)->nullable();
            $table->string('label', 60)->nullable();
            $table->string('note', 300)->nullable();
            // Only meaningful for kinds that do not resolve to a route of ours.
            $table->string('url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'kind']);
        });

        Schema::create('presentation_funnels', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('slug', 190)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            // The video everybody starts on. Nullable so a funnel can be built
            // before its first step exists.
            $table->foreignId('entry_presentation_id')->nullable()
                ->constrained('presentations')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            // Whether members may share it, mirroring `member_schedulable` on
            // recordings: head office decides what is fit to put in front of a
            // prospect.
            $table->boolean('member_shareable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // A step of a funnel is an ordinary always-open presentation. That is
        // the point: it inherits the room, the chat, the isolation, the
        // heartbeat and the reporting without any of it being rebuilt.
        Schema::table('presentations', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable()->after('series_id')
                ->constrained('presentation_funnels')->nullOnDelete();
            $table->unsignedSmallInteger('funnel_order')->default(0)->after('funnel_id');
        });

        Schema::create('presentation_cues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained()->cascadeOnDelete();

            // 'cta' offers something that ends the journey; 'branch' plays
            // another video. Same shape because to the guest they are the same
            // thing — a button that appeared when it became relevant.
            $table->string('kind', 16)->default('cta');
            $table->foreignId('cta_item_id')->nullable()->constrained('cta_items')->nullOnDelete();
            $table->foreignId('next_presentation_id')->nullable()
                ->constrained('presentations')->nullOnDelete();

            // Wording for this placement, overriding the item's own.
            $table->string('label', 80)->nullable();
            $table->string('note', 300)->nullable();

            // When it appears, and optionally when it goes away again.
            $table->unsignedInteger('starts_at_seconds')->default(0);
            $table->unsignedInteger('ends_at_seconds')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['presentation_id', 'starts_at_seconds']);
        });

        Schema::create('funnel_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('funnel_id')->constrained('presentation_funnels')->cascadeOnDelete();

            /*
             * The whole reason this table exists.
             *
             * Attribution is decided once, when they enter, and then carried
             * for the rest of the journey. Without it a prospect who follows
             * three branches is three unrelated guests and nobody can say whose
             * they are — which is the argument this feature must never start.
             */
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('token', 64)->unique();

            // Where they are now, and the attendee row their conversation is
            // anchored to — one thread for the whole journey, not one per video.
            $table->foreignId('current_presentation_id')->nullable()
                ->constrained('presentations')->nullOnDelete();
            $table->foreignId('thread_attendee_id')->nullable()
                ->constrained('presentation_attendees')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // What the journey ended in, once it ends in anything.
            $table->string('outcome', 32)->nullable();
            $table->timestamps();

            $table->index(['funnel_id', 'host_user_id']);
            $table->index('email');
            // One journey per person per funnel per inviting member. A second
            // member inviting the same address is a separate relationship, the
            // same way it is for attendees.
            $table->unique(['funnel_id', 'host_user_id', 'email'], 'funnel_participants_unique_person');
        });

        Schema::table('presentation_attendees', function (Blueprint $table) {
            $table->foreignId('funnel_participant_id')->nullable()->after('host_user_id')
                ->constrained('funnel_participants')->nullOnDelete();
        });

        Schema::create('funnel_choice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('presentation_funnels')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('funnel_participants')->cascadeOnDelete();
            $table->foreignId('presentation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cue_id')->nullable()->constrained('presentation_cues')->nullOnDelete();

            // Snapshotted, because a cue can be reworded or deleted later and
            // the record of what somebody actually chose must not change.
            $table->string('kind', 16);
            $table->string('label', 120);
            $table->foreignId('next_presentation_id')->nullable()
                ->constrained('presentations')->nullOnDelete();
            $table->foreignId('cta_item_id')->nullable()->constrained('cta_items')->nullOnDelete();

            // How far into the video they were when they chose it.
            $table->unsignedInteger('at_seconds')->nullable();
            $table->timestamps();

            $table->index(['funnel_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_choice_events');

        Schema::table('presentation_attendees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funnel_participant_id');
        });

        Schema::dropIfExists('funnel_participants');
        Schema::dropIfExists('presentation_cues');

        Schema::table('presentations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funnel_id');
            $table->dropColumn('funnel_order');
        });

        Schema::dropIfExists('presentation_funnels');
        Schema::dropIfExists('cta_items');
    }
};
