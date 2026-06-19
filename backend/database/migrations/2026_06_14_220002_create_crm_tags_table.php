<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system-wide
            $table->string('name', 80);
            $table->string('color', 20)->default('#6c757d');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('crm_contact_tags', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained('crm_contacts')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('crm_tags')->onDelete('cascade');
            $table->primary(['contact_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_tags');
        Schema::dropIfExists('crm_tags');
    }
};
