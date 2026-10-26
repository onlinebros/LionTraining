<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Q3-AI Assistant on support tickets: staff ask it for a draft reply and
 * whether the ticket needs something the application does not do yet. Turns
 * are answered by the Request Assistant's relay on dev. Kept in its own tables
 * so support_tickets is not altered. See memory-bank/request-assistant.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_assists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('support_tickets')->cascadeOnDelete();

            // reply: the turn waiting for the relay. Cleared when it is answered.
            $table->string('pending', 8)->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('busy_since')->nullable();

            $table->uuid('claude_session_id')->nullable();
            $table->string('snapshot_commit', 40)->nullable();

            // The change request started from this ticket's finding, if any.
            $table->foreignId('change_request_id')->nullable()->constrained('change_requests')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('support_assist_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_assist_id')->constrained('support_assists')->cascadeOnDelete();

            // user (staff) | assistant | error
            $table->string('role', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('body');

            // For assistant turns: verdict, draft, cost, steps, model.
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->index(['support_assist_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_assist_messages');
        Schema::dropIfExists('support_assists');
    }
};
