<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['flat', 'percentage', 'tiered']);
            $table->json('config');                      // {"amount":50} | {"rate":0.10} | {"tiers":[...]}
            $table->unsignedInteger('clawback_window_days')->nullable(); // null = manual-only
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_plans');
    }
};
