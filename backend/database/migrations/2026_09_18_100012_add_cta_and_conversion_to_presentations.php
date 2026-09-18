<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things: what the call to action asks for, and whether it worked.
     *
     * The same presentation machinery is used to recruit members and to win
     * customers, so the button at the end cannot be hard-wired to one of them.
     * And a guest who watches under one email and signs up under another is the
     * normal case, not an edge case — people give a throwaway address until
     * they have decided — so conversion is tracked by a token carried through
     * the click, with email matching only as a fallback.
     */
    public function up(): void
    {
        foreach (['presentations', 'presentation_series'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // join | customer | custom | none
                $t->string('cta_type')->default('join')->after('replay_visibility');
                $t->string('cta_headline')->nullable()->after('cta_type');
                $t->string('cta_label')->nullable()->after('cta_headline');
                $t->text('cta_note')->nullable()->after('cta_label');
                $t->string('cta_url')->nullable()->after('cta_note');
            });
        }

        Schema::table('presentation_attendees', function (Blueprint $table) {
            /*
             * The account this guest became. Not matched on email: the whole
             * point is that the address changes. Set from a token carried
             * through the call-to-action click, which survives any change of
             * email, with email matching as a later fallback.
             */
            $table->foreignId('converted_user_id')
                ->nullable()
                ->after('cta_clicked_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('converted_at')->nullable()->after('converted_user_id');

            // token | email | manual — how we know, which decides how much to
            // trust it when the two addresses disagree.
            $table->string('conversion_match', 16)->nullable()->after('converted_at');

            // Handed to the call-to-action link so a signup can be traced back
            // here regardless of what address it used.
            $table->string('cta_token', 64)->nullable()->unique()->after('conversion_match');

            $table->foreignId('crm_contact_id')
                ->nullable()
                ->after('cta_token')
                ->constrained('crm_contacts')
                ->nullOnDelete();

            $table->index(['host_user_id', 'email']);
            $table->index('converted_user_id');
        });
    }

    public function down(): void
    {
        foreach (['presentations', 'presentation_series'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['cta_type', 'cta_headline', 'cta_label', 'cta_note', 'cta_url']);
            });
        }

        Schema::table('presentation_attendees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_user_id');
            $table->dropConstrainedForeignId('crm_contact_id');
            $table->dropColumn(['converted_at', 'conversion_match', 'cta_token']);
        });
    }
};
