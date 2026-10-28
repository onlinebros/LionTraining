<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * B2B accounts and client microsites, on top of the Proposal Studio. See
 * memory-bank/b2b-accounts.md.
 *
 * A business account is the company we are building a relationship with: its
 * people, its stage, and a timeline. Each existing prospect becomes one of its
 * opportunities (one offering each). The account's microsite is a private,
 * co-branded site its invited people open with an emailed link.
 *
 * Additive only: new tables, and one nullable column on prospects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('size', 40)->nullable();
            $table->string('headquarters')->nullable();
            // target → engaged → customer, or dormant
            $table->string('stage', 20)->default('target')->index();
            $table->text('notes')->nullable();
            $table->string('next_step')->nullable();
            $table->date('next_step_due')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The microsite. Its address is made the first time it is needed.
            $table->string('site_ref', 16)->nullable()->unique();
            $table->string('site_title')->nullable();
            $table->text('site_intro')->nullable();
            // Show invited people the titles of sections still being prepared.
            $table->boolean('site_show_progress')->default(true);
            // Co-branding, uploaded by us (owner, 2026-10-28).
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->timestamps();
        });

        // The account's people. Roles are how we sell to them, not what they
        // may see on the microsite; that is microsite_members.
        Schema::create('business_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            // decision_maker | champion | finance | facilities | technical | other
            $table->string('role', 20)->default('other');
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // One timeline: notes, calls, meetings, tasks (a due date), and what
        // the client does on the microsite (type "client").
        Schema::create('business_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12);
            $table->text('body');
            $table->json('meta')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['business_account_id', 'created_at']);
        });

        Schema::table('prospects', function (Blueprint $table) {
            $table->foreignId('business_account_id')->nullable()->after('offering_id')->constrained()->nullOnDelete();
        });

        Schema::create('microsite_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // welcome | challenges | solution | evidence | proposal | documents | faq | next_steps | custom
            $table->string('kind', 16);
            $table->string('title');
            // What the admin wants the section to say; the assistant's brief.
            $table->text('brief')->nullable();
            $table->longText('body')->nullable();
            $table->json('claim_ids')->nullable();
            // The opportunity a section is about: its offering's claims and
            // documents, and for "proposal", its published proposal.
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            // Only members who may see pricing see this section.
            $table->boolean('pricing_only')->default(false);
            // draft → published
            $table->string('status', 12)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->timestamps();
            $table->index(['business_account_id', 'position']);
        });

        // Who may open the microsite. Only the token's SHA-256 is kept.
        Schema::create('microsite_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            // viewer | contributor
            $table->string('role', 12)->default('viewer');
            $table->boolean('can_see_pricing')->default(false);
            // invited | requested (by another member, waiting for an admin) | declined
            $table->string('status', 12)->default('invited');
            $table->foreignId('requested_by_member_id')->nullable()->constrained('microsite_members')->nullOnDelete();
            $table->text('request_note')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_account_id', 'email']);
        });

        // Feedback a contributor leaves on a section, and our reply.
        Schema::create('microsite_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('microsite_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('microsite_member_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });

        $this->backfillAccounts();
    }

    // Every existing prospect gets an account of its own, carrying its contact.
    private function backfillAccounts(): void
    {
        foreach (DB::table('prospects')->whereNull('business_account_id')->orderBy('id')->get() as $p) {
            $id = DB::table('business_accounts')->insertGetId([
                'name' => $p->company_name,
                'website' => $p->website,
                'industry' => $p->industry,
                'stage' => $p->status === 'won' ? 'customer' : ($p->status === 'lost' ? 'dormant' : 'engaged'),
                'created_by' => $p->created_by,
                'owner_user_id' => $p->created_by,
                'created_at' => $p->created_at,
                'updated_at' => now(),
            ]);
            if ($p->contact_name || $p->contact_email) {
                DB::table('business_contacts')->insert([
                    'business_account_id' => $id,
                    'name' => $p->contact_name ?: Str::before((string) $p->contact_email, '@'),
                    'email' => $p->contact_email,
                    'phone' => $p->contact_phone,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('prospects')->where('id', $p->id)->update(['business_account_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('microsite_comments');
        Schema::dropIfExists('microsite_members');
        Schema::dropIfExists('microsite_sections');
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_account_id');
        });
        Schema::dropIfExists('business_activities');
        Schema::dropIfExists('business_contacts');
        Schema::dropIfExists('business_accounts');
    }
};
