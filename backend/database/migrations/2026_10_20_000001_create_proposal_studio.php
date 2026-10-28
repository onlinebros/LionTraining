<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal Studio, phase 0: the offerings we present, the documents the
 * assistant learns them from, and a workspace per prospect company with its
 * discovery conversation. See memory-bank/proposal-studio.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A product line we represent. PlasmaGuard first; a second line is a
        // new row and its documents, not new code.
        Schema::create('offerings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('vendor_name');
            $table->string('vendor_url')->nullable();
            // The business line in config/opportunities.php it belongs to, if any.
            $table->string('opportunity_key', 40)->nullable();
            $table->text('summary')->nullable();
            // How the assistant should present this offering, in our words.
            $table->text('ai_guidance')->nullable();
            // Claims that must never be made. Sent with every prompt.
            $table->text('prohibited_claims')->nullable();
            // What we need to learn about a prospect, one item per line.
            $table->text('discovery_checklist')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // What the assistant knows an offering from. Text is extracted once,
        // at upload, and is what the prompt carries.
        Schema::create('offering_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 30)->default('other');
            // internal: for us only. shareable: may be shown to a prospect.
            $table->string('audience', 20)->default('internal');
            // Approved for the assistant to read and quote from.
            $table->boolean('use_for_ai')->default(true);
            $table->string('source_url', 2048)->nullable();
            $table->date('read_on')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('text_chars')->default(0);
            $table->string('extraction_error', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained()->restrictOnDelete();
            $table->string('company_name');
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            // new → discovery → proposal → won | lost
            $table->string('status', 20)->default('discovery')->index();
            // What the assistant has worked out so far: summary, facts, sites,
            // open questions. Rewritten by each reply.
            $table->json('profile')->nullable();
            // Recommended plan and email drafts, as Markdown.
            $table->longText('draft')->nullable();
            $table->timestamp('draft_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // A turn waiting for the worker (reply | draft), when it was taken,
            // and since when the page has shown the assistant as busy.
            $table->string('assistant_pending', 10)->nullable()->index();
            $table->timestamp('assistant_claimed_at')->nullable();
            $table->timestamp('assistant_busy_since')->nullable();
            $table->timestamps();
        });

        Schema::create('prospect_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 12); // user | assistant | error
            $table->longText('body');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'role', 'created_at']);
        });

        DB::table('offerings')->insert([
            'key' => 'plasmaguard',
            'name' => 'PlasmaGuard air purification',
            'vendor_name' => 'PlasmaGuard',
            'vendor_url' => 'https://plasmaguard.com',
            'opportunity_key' => 'plasmaguard',
            'summary' => 'Non-thermal cold plasma air purification for businesses. First product sold through Q3: the PlasmaGuard PRO In-Duct System.',
            'discovery_checklist' => implode("\n", [
                'Industry and what the business does',
                'Number of locations, and where they are',
                'Square footage of each location',
                'HVAC: type of system, number of air handlers or units, duct access',
                'How many people are in the space, and how often',
                'What prompted the interest: odors, allergens, illness, smoke, mold, regulations, reputation',
                'What they use for air quality today',
                'Who decides, who else has a say, and who maintains the HVAC',
                'Timeline, and anything driving it',
                'Budget expectations or how purchases like this are approved',
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_messages');
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('offering_documents');
        Schema::dropIfExists('offerings');
    }
};
