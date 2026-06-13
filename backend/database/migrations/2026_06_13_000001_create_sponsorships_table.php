<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('sponsored_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'active', 'inactive'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['sponsor_id', 'sponsored_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorships');
    }
};
