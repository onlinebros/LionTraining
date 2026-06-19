<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->enum('type', [
                'note', 'call', 'email', 'sms', 'meeting', 'zoom', 'social', 'voicemail', 'other',
            ])->default('note');

            $table->string('title')->nullable();
            $table->text('body');
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
    }
};
