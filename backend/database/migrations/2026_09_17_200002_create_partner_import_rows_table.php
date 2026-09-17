<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staging area: one row per CSV line, as given, before anything is real.
 *
 * Staging exists because a partner's list is the one thing in this system we
 * cannot fix after the fact. Placement paths are permanent by design — there is
 * no "move this spot" operation, deliberately — so a list committed with the
 * parent column off by one is not a bug that gets patched, it is a genealogy
 * that has to be rebuilt by hand with people already sitting in it.
 *
 * So the CSV lands here first, gets checked as a whole tree, gets its top rows
 * hand-mapped onto our existing users, and only then becomes users rows.
 *
 * The activation code is held in plaintext here and hashed on the way into
 * `users`. It is a credential, so it does not stay in plaintext after the batch
 * is committed — commit clears the column, and until then the row is what an
 * admin checks a support call against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('partner_import_id')->constrained()->cascadeOnDelete();

            // Line number in the uploaded file, header excluded. Every error
            // message quotes it, because that is what the person fixing the
            // spreadsheet is looking at.
            $table->unsignedInteger('line_number');

            // ── Identity, as the partner company holds it ────────────────────
            $table->string('external_user_id', 64);
            $table->string('activation_code', 128)->nullable();

            // ── Structure, in the partner's own identifiers ──────────────────
            // Blank parent means a top row: it has no parent inside this file
            // and must be mapped onto one of our users before commit.
            $table->string('external_parent_id', 64)->nullable();
            // Optional. When the partner tracks recruitment separately from
            // position, this is recruitment; blank means the two agree.
            $table->string('external_sponsor_id', 64)->nullable();

            // There is deliberately no name, email, phone or address column
            // here. The import carries positions, not people: a member supplies
            // their own details to us when they claim, having chosen to. See
            // SpotImportTemplate for what that buys and what it costs.
            //
            // There is also deliberately no `raw` column for the parts of the
            // file we did not map. A column we keep "just in case" is exactly
            // how the personal data we asked not to receive ends up stored
            // anyway — the parser discards those values and records only the
            // column names, on the batch.

            // ── Connecting a top row to somebody already in our system ───────
            // What an admin typed on the review screen — one of OUR users, by
            // email or id — and what it resolved to. Both are kept: the
            // resolved link is what commit uses, and the typed one is the audit
            // trail when a partner asks why a leg landed where it did, and the
            // only way to tell "nobody has connected this yet" from "the
            // account it was connected to has since been deleted".
            $table->string('link_to_existing')->nullable();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->nullOnDelete();

            // ── State ────────────────────────────────────────────────────────
            // pending → valid | invalid, then committed | skipped
            $table->string('status', 16)->default('pending');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();

            // The users row this line became. Null until commit.
            $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Two members of the same company cannot share an identifier. This
            // is scoped to the batch rather than the company because a
            // correction batch legitimately re-states identifiers the failed
            // batch already used; cross-batch collisions are caught at commit,
            // against the unique index on users.
            $table->unique(['partner_import_id', 'external_user_id']);
            $table->index(['partner_import_id', 'status']);
            $table->index(['partner_import_id', 'external_parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_import_rows');
    }
};
