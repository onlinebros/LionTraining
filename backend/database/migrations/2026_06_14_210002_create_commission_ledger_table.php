<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('earner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_plan_id')->nullable()->constrained('commission_plans')->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('commission_payouts')->nullOnDelete();
            $table->nullableMorphs('source');            // polymorphic: what triggered this entry
            $table->enum('type', ['credit', 'debit']);  // credit=earned, debit=clawback/adjustment
            $table->decimal('amount', 15, 4);
            $table->enum('status', ['pending', 'approved', 'paid', 'voided'])->default('pending');
            $table->date('clawback_eligible_until')->nullable(); // null = no automatic window
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger');
    }
};
