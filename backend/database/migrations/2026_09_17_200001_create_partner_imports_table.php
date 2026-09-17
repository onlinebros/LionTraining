<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One upload of a partner company's list.
 *
 * A batch moves uploaded → validated → committed and never goes backwards. It
 * exists as its own row rather than as a flag on the staged rows so that a
 * second upload from the same company — a correction, or a later tranche — is a
 * new batch sitting beside the first, not an edit to data we have already
 * turned into live genealogy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('partner_company_id')->constrained()->cascadeOnDelete();

            $table->string('original_filename');
            // Where the uploaded file is kept, so a disputed import can be
            // re-read against exactly the bytes we were given.
            $table->string('stored_path')->nullable();

            // uploaded  — parsed into staging, not yet checked as a whole
            // validated — every row passed, and the tree it describes is sound
            // failed    — at least one row is unusable; nothing may be committed
            // committed — users rows exist; this batch is now history
            $table->string('status', 16)->default('uploaded');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);

            // Batch-level problems that belong to no single line: a cycle, a
            // missing header, a file that is not a CSV at all.
            $table->json('errors')->nullable();

            // Names of columns in the file we did not recognise and whose
            // values we therefore discarded. Names only, never values — the
            // point of recording them is so an admin can tell the partner
            // "you sent us an email column and we threw it away", which is
            // impossible if throwing it away left no trace.
            $table->json('ignored_columns')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['partner_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_imports');
    }
};
