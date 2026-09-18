<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a call to action opens beside the video or replaces it.
     *
     * Both are right, for different asks. A form somebody fills in and comes
     * back from wants a new window — they keep their place, and the member is
     * still there in the chat. A page that is the next thing, that they are
     * meant to stay on, wants the tab: sending them to a second window they
     * then have to find their way out of is worse than simply taking them
     * there.
     *
     * Defaults to a new window everywhere, which is what every existing button
     * already does.
     */
    public function up(): void
    {
        Schema::table('cta_items', function (Blueprint $table) {
            // new_window | same_tab
            $table->string('opens_in', 16)->default('new_window')->after('url');
        });

        // The showing's own inline call to action gets the same choice, so the
        // two paths behave the same way rather than one being the exception.
        Schema::table('presentations', function (Blueprint $table) {
            $table->string('cta_opens_in', 16)->default('new_window')->after('cta_url');
        });
    }

    public function down(): void
    {
        Schema::table('cta_items', function (Blueprint $table) {
            $table->dropColumn('opens_in');
        });

        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn('cta_opens_in');
        });
    }
};
