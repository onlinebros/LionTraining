<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the launch special has credited, and why.
         *
         * Each row is backed by one commission ledger credit, which is what
         * payouts pay. This table records which place or pool contribution the
         * credit is for, so PromotionBonuses can match it against the current
         * standings and void or add credits when the standings change.
         *
         *   place — the bonus for one unit (a system) among the first N sold.
         *           vendor_lead_id is the order, unit_index the system in it.
         *   pool  — one earner's share of what one later order put into the
         *           pool. vendor_lead_id is the contributing order.
         */
        Schema::create('promotion_awards', function (Blueprint $table) {
            $table->id();
            $table->string('promotion_key', 64);
            $table->string('kind', 16);
            $table->foreignId('vendor_lead_id')->constrained('vendor_leads')->cascadeOnDelete();
            $table->unsignedSmallInteger('unit_index')->default(0);
            $table->foreignId('earner_id')->constrained('users')->restrictOnDelete();

            // Display only: the place (or first pool sale) number when last matched.
            $table->unsignedInteger('place')->nullable();
            $table->unsignedInteger('units')->default(1);
            $table->unsignedInteger('shares')->default(1);

            $table->decimal('amount', 15, 4);
            $table->foreignId('commission_ledger_id')->nullable()->constrained('commission_ledger')->nullOnDelete();

            // active | voided | needs_clawback (it should be undone, but was already approved or paid)
            $table->string('status', 16)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['promotion_key', 'status']);
            $table->index(['earner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_awards');
    }
};
