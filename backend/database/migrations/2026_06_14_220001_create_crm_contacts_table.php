<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Link to platform users (if contact becomes a member)
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Classification
            $table->enum('contact_type', [
                'prospect', 'lead', 'customer', 'affiliate', 'team_member', 'vendor', 'partner',
            ])->default('prospect');

            $table->enum('status', [
                'new', 'contacted', 'interested', 'presentation_sent', 'follow_up_needed',
                'application_started', 'purchased', 'subscribed', 'became_affiliate',
                'not_interested', 'lost', 're_engage_later',
            ])->default('new');

            $table->enum('lead_source', [
                'referral', 'website', 'social', 'cold_outreach', 'event', 'ad', 'other',
            ])->nullable();

            // Core fields
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('website')->nullable();

            // Location
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();

            // Notes & custom data
            $table->text('quick_note')->nullable();
            $table->json('custom_data')->nullable();

            // Dates
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
