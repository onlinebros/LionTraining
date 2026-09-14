<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();

            // Idempotency key — Stripe guarantees `id` is unique per event and
            // re-deliveries reuse it. The UNIQUE index is what makes "process
            // exactly once" enforceable at the database level: a duplicate
            // POST hits a constraint violation and is short-circuited.
            $table->string('stripe_event_id', 191)->unique();

            $table->string('type', 100);                  // e.g. 'invoice.paid', 'charge.refunded'
            $table->string('api_version', 32)->nullable();
            $table->unsignedBigInteger('event_created_at')->nullable(); // Stripe's `created` epoch, for audit
            $table->boolean('livemode')->default(false);

            // Raw body bytes as received — re-verifiable, replayable, and the
            // canonical source of truth for any downstream commission accrual.
            $table->longText('payload');

            // Full Stripe-Signature header, retained for forensics.
            $table->text('signature');

            $table->enum('status', ['received', 'processed', 'failed'])->default('received');
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
