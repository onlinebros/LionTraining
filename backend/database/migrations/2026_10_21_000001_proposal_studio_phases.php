<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal Studio, phases 1 to 4: one queue for every piece of AI work, the
 * knowledge library's passages, claims and questions, versioned proposals,
 * and the client portal. See memory-bank/proposal-studio.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every piece of AI work, waiting for a worker. On dev the worker
        // answers its own; live's are collected by dev's worker (the relay).
        Schema::create('studio_jobs', function (Blueprint $table) {
            $table->id();
            // prospect_reply | prospect_draft | proposal_draft | claim_extract | library_answer
            $table->string('kind', 30);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // pending → claimed → done | failed
            $table->string('status', 12)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['subject_type', 'subject_id', 'status']);
        });

        // Prospect turns moved onto the queue.
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropIndex(['assistant_pending']);
            $table->dropColumn(['assistant_pending', 'assistant_claimed_at', 'assistant_busy_since']);
        });

        Schema::table('prospects', function (Blueprint $table) {
            // The client portal's address: /proposal/{portal_ref}.
            $table->string('portal_ref', 24)->nullable()->unique();
            $table->text('outcome_note')->nullable();
        });

        // The vendor slug in config/vendors.php, which is how the Product
        // Partner portal knows whose proposals these are.
        Schema::table('offerings', function (Blueprint $table) {
            $table->string('vendor_key', 40)->nullable()->after('vendor_name');
        });
        DB::table('offerings')->where('key', 'plasmaguard')->update(['vendor_key' => 'plasmaguard']);

        // Documents cut into passages for search, when they will not all fit
        // in a prompt.
        Schema::create('knowledge_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('text');
            $table->index(['offering_id', 'offering_document_id']);
        });

        // Claims quoted word for word from a document. Only approved ones may
        // reach a prospect, and they are always rendered from this row.
        Schema::create('knowledge_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_document_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 120)->nullable();
            $table->text('quote');
            $table->text('footnote')->nullable();
            // draft | approved | rejected
            $table->string('status', 12)->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['offering_id', 'status']);
        });

        Schema::create('library_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // draft → in_review (vendor) → published → superseded
            $table->string('status', 16)->default('draft');
            $table->json('content')->nullable();
            // not_required | pending | approved | changes_requested
            $table->string('vendor_review', 20)->default('not_required');
            $table->text('vendor_review_note')->nullable();
            $table->foreignId('vendor_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vendor_reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['prospect_id', 'version']);
        });

        // Who may open a prospect's portal. Only the token's SHA-256 is kept.
        Schema::create('portal_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['prospect_id', 'email']);
        });

        // What a prospect sends from the portal: details, corrections,
        // questions, a quote or survey request. Questions carry their answer.
        Schema::create('portal_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_invite_id')->nullable()->constrained()->nullOnDelete();
            // details | correction | question | quote_request | survey_request
            $table->string('kind', 20);
            $table->longText('body')->nullable();
            $table->json('fields')->nullable();
            $table->json('files')->nullable();
            // Questions: assistant | admin | pending
            $table->string('answered_by', 12)->nullable();
            $table->longText('answer')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['prospect_id', 'kind']);
        });

        Schema::create('portal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_invite_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['prospect_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_events');
        Schema::dropIfExists('portal_submissions');
        Schema::dropIfExists('portal_invites');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('library_questions');
        Schema::dropIfExists('knowledge_claims');
        Schema::dropIfExists('knowledge_passages');
        Schema::table('offerings', fn (Blueprint $table) => $table->dropColumn('vendor_key'));
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropUnique(['portal_ref']);
            $table->dropColumn(['portal_ref', 'outcome_note']);
            $table->string('assistant_pending', 10)->nullable()->index();
            $table->timestamp('assistant_claimed_at')->nullable();
            $table->timestamp('assistant_busy_since')->nullable();
        });
        Schema::dropIfExists('studio_jobs');
    }
};
