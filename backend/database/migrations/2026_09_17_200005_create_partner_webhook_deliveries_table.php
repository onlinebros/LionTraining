<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per outbound event, kept whether it lands or not.
 *
 * A ledger rather than fire-and-forget, for the same reason the inbound Stripe
 * events get one: when a partner says "we never heard about that claim", the
 * only useful answer is a row showing what we sent, when, how many times, and
 * what their endpoint said back. Without it the conversation is two parties
 * each certain the other is wrong.
 *
 * The built payload is stored, not rebuilt at send time, so a replay sends
 * exactly the bytes the first attempt did — a partner reconciling a replay
 * against their log should see the same event, not a fresher one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('partner_company_id')->constrained()->cascadeOnDelete();

            // Ours, and stable across retries and replays. The partner keys
            // their own idempotency on it: every retry of the same claim
            // carries the same event_id, so a slow endpoint that times out
            // after processing cannot double-apply.
            $table->uuid('event_id')->unique();
            $table->string('event_type', 64);

            // The spot this is about. Nullable only so a test ping has
            // somewhere to live; every real event has one.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_user_id', 64)->nullable();

            $table->json('payload');

            // pending → delivered | failed. 'failed' means the retries are
            // exhausted, not that the last attempt errored.
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();

            // Truncated. It is for a human diagnosing a 4xx, and an endpoint
            // that answers with a megabyte of HTML must not be able to fill
            // this table.
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();

            $table->timestamps();

            $table->index(['partner_company_id', 'status']);
            $table->index(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_deliveries');
    }
};
