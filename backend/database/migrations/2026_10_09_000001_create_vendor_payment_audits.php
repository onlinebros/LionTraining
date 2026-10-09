<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the vendor's Stripe says happened to each order we built on it.
 *
 * One row per order, overwritten on every audit, with the attempts merged in
 * rather than replaced: Stripe drops events after 30 days, and the history of
 * why a customer could not pay is the thing this table exists to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_lead_id')->unique()->constrained('vendor_leads')->cascadeOnDelete();
            $table->string('vendor', 64)->index();
            $table->string('payment_intent_id', 191)->index();

            // Our conclusion, from the intent's status read against our own.
            $table->string('verdict', 32)->index();

            // The intent as Stripe holds it now.
            $table->string('intent_status', 40)->nullable();
            $table->unsignedBigInteger('intent_amount')->nullable();
            $table->timestamp('intent_created_at')->nullable();
            $table->string('cancellation_reason', 64)->nullable();
            $table->string('next_action', 64)->nullable();

            // The most recent attempt that failed, and how.
            $table->string('payment_method_type', 40)->nullable();
            $table->string('failure_type', 40)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('decline_code', 80)->nullable();
            $table->text('failure_message')->nullable();

            // Stripe's own reading of the latest charge, in their words.
            $table->string('outcome_type', 40)->nullable();
            $table->string('outcome_reason', 80)->nullable();
            $table->text('seller_message')->nullable();
            $table->string('risk_level', 20)->nullable();

            $table->json('attempts')->nullable();

            // Why the last read did not complete, when it did not.
            $table->text('check_error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_audits');
    }
};
