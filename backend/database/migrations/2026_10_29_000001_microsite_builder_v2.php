<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The microsite builder, second pass (memory-bank/b2b-accounts.md):
 * - an account presents an offering, so every section can draw on its
 *   library without an opportunity;
 * - a site goal and a theme;
 * - sections have a layout and structured content the assistant fills in,
 *   and an instruction for the next redraft;
 * - reading time per section, per person, per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->foreignId('offering_id')->nullable()->after('stage')->constrained('offerings')->nullOnDelete();
            $table->text('site_goal')->nullable()->after('site_intro');
            $table->string('site_theme', 20)->nullable()->after('site_goal');
        });

        Schema::table('microsite_sections', function (Blueprint $table) {
            $table->string('layout', 20)->default('text')->after('kind');
            $table->json('content')->nullable()->after('body');
            $table->text('ai_instruction')->nullable()->after('brief');
        });

        Schema::create('microsite_section_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('microsite_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('microsite_member_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamps();
            $table->unique(['microsite_section_id', 'microsite_member_id', 'day'], 'msv_section_member_day');
        });

        // Accounts with one offering among their opportunities present it.
        foreach (DB::table('business_accounts')->whereNull('offering_id')->pluck('id') as $id) {
            $offerings = DB::table('prospects')->where('business_account_id', $id)->distinct()->pluck('offering_id');
            if ($offerings->count() === 1) {
                DB::table('business_accounts')->where('id', $id)->update(['offering_id' => $offerings->first()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('microsite_section_views');
        Schema::table('microsite_sections', function (Blueprint $table) {
            $table->dropColumn(['layout', 'content', 'ai_instruction']);
        });
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offering_id');
            $table->dropColumn(['site_goal', 'site_theme']);
        });
    }
};
