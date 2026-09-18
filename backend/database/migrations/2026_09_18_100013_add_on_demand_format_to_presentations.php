<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A second shape of showing: always open, watched from the start.
     *
     * A scheduled presentation is one shared moment — everybody sees the same
     * frame at the same time. An on-demand share is the opposite: whenever
     * somebody opens the link, it begins for them, at zero, on their own clock.
     *
     * Everything around it is unchanged — the same invite codes, the same
     * isolated conversations, the same console, the same conversion tracking —
     * so this is a format on the existing model rather than a parallel one.
     *
     * Deliberately not on `presentation_series`: a series is a repeating
     * booking, and repeating something that is already always open means
     * nothing.
     */
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            // scheduled | on_demand
            $table->string('format', 16)->default('scheduled')->after('title');
        });

        Schema::table('presentation_attendees', function (Blueprint $table) {
            /*
             * The furthest point this viewer has reached.
             *
             * On a shared showing the room's clock answers "how far in are we".
             * On an on-demand share there is no room clock, so each viewer
             * carries their own — and this is what lets them pick up where they
             * left off, and what stops them skipping past a part they have not
             * seen while still being free to go back over one they have.
             */
            $table->unsignedInteger('furthest_seconds')->default(0)->after('position_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn('format');
        });

        Schema::table('presentation_attendees', function (Blueprint $table) {
            $table->dropColumn('furthest_seconds');
        });
    }
};
