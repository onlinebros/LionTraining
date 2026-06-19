<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contact_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // e.g. contact_created, status_changed, note_added, followup_created,
            //      followup_completed, tag_added, tag_removed, field_updated
            $table->string('type', 60);
            $table->string('description');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_activities');
    }
};
