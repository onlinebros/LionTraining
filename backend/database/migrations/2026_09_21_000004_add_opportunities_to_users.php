<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which business line each member came in for — see config/opportunities.php.
 *
 * Two pieces of state, because they answer different questions:
 *
 *   users.primary_opportunity  the one they came in by, denormalised onto the
 *                          row because it is read on every gated request. A
 *                          join per page view to decide whether somebody needs
 *                          a card is not worth the normalisation. It is named
 *                          `primary_opportunity` and not `opportunity` so that
 *                          User::opportunity() can exist without Eloquent
 *                          mistaking it for a relation whenever a select()
 *                          leaves the column out.
 *   user_opportunities     every line they hold, including the primary. This is
 *                          what "categorise a member by what they are
 *                          interested in" means, and what the feature union in
 *                          User::canSee() reads.
 *
 * The key is a plain string, not a foreign key: the registry is config, so
 * there is no table to point at, and a key removed from the registry has to
 * degrade to the default rather than break a login.
 *
 * NOTHING IS BACKFILLED. Null reads as the default opportunity, which is the
 * one whose behaviour is exactly what every existing account already has. A
 * backfill here would write several hundred thousand rows to say "unchanged".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = the default opportunity. See App\Support\Opportunity::get().
            $table->string('primary_opportunity', 64)->nullable()->index();

            // The front door: the host of the marketing site they arrived
            // through, recorded verbatim so a second replicated site is
            // answerable without adding a column. Null for anyone who signed up
            // before there was more than one.
            $table->string('entry_site', 120)->nullable();
        });

        Schema::create('user_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('opportunity', 64);

            /*
            | How the association happened: 'signup' (the door they came in
            | by), 'self' (they added it from the back office), 'admin' (staff
            | did), 'import'. Kept because "why does this person see the
            | training library" is a support question, and the answer is
            | otherwise unrecoverable.
            */
            $table->string('source', 32)->default('signup');

            // Who added it, when staff did.
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per line per member. associate() relies on this.
            $table->unique(['user_id', 'opportunity']);
            $table->index('opportunity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_opportunities');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['primary_opportunity', 'entry_site']);
        });
    }
};
