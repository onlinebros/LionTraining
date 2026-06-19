<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_clawbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_ledger_id')->constrained('commission_ledger')->restrictOnDelete();
            $table->foreignId('debit_ledger_id')->nullable()->constrained('commission_ledger')->nullOnDelete();
            $table->foreignId('earner_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->text('reason');
            $table->enum('status', ['pending', 'applied', 'reversed'])->default('pending');
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->boolean('override_window')->default(false); // admin bypassed the eligible window
            $table->text('override_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_clawbacks');
    }
};
