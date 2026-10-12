<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Request Assistant: a super admin describes a change, a read-only Claude
 * works it into a plan with them, and it is decided on dev whether it is built.
 * See memory-bank/request-assistant.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');

            // drafting → submitted → approved | rejected | needs_info → implemented
            $table->string('status', 20)->default('drafting')->index();

            // The plan the assistant drafted, as Markdown. Redrafted on request.
            $table->longText('plan')->nullable();
            $table->timestamp('plan_drafted_at')->nullable();

            // The CLI conversation this request continues, and the commit the
            // assistant was reading when it last answered.
            $table->uuid('claude_session_id')->nullable();
            $table->string('snapshot_commit', 40)->nullable();

            // Set while a reply is being written; the page polls until it clears.
            $table->timestamp('assistant_busy_since')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();
        });

        Schema::create('change_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();

            // user | assistant | reviewer | error
            $table->string('role', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('body');

            // For assistant turns: cost, tool turns, duration, model.
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->index(['change_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_messages');
        Schema::dropIfExists('change_requests');
    }
};
