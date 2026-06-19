<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();          // slug: free_member, paid_member, etc.
            $table->string('display_name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_admin')->default(false);   // can access /admin
            $table->unsignedTinyInteger('level')->default(0); // 0=free 1=paid 10=support 99=super
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
