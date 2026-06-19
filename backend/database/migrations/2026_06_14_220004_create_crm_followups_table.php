<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts')->onDelete('cascade');
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users');

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('type', [
                'call', 'email', 'sms', 'meeting', 'zoom', 'social', 'other',
            ])->default('call');

            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            $table->enum('status', [
                'pending', 'completed', 'overdue', 'snoozed', 'cancelled',
            ])->default('pending');

            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->text('completion_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_followups');
    }
};
