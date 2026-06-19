<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kartra_imports', function (Blueprint $table) {
            $table->id();

            // Kartra content hierarchy
            $table->string('kartra_type');            // module | lesson | video | page
            $table->string('kartra_id')->nullable();  // Kartra's own ID if discoverable
            $table->string('kartra_title');
            $table->text('kartra_description')->nullable();
            $table->string('kartra_url', 2000)->nullable();
            $table->string('kartra_video_url', 2000)->nullable();
            $table->string('kartra_thumbnail_url', 2000)->nullable();
            $table->unsignedInteger('kartra_order')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('kartra_imports')->nullOnDelete();

            // Processing state
            $table->enum('status', ['discovered', 'downloading', 'downloaded', 'mapped', 'skipped', 'failed'])
                  ->default('discovered');
            $table->text('error_message')->nullable();

            // Mappings to local training content (filled after admin review)
            $table->foreignId('local_category_id')->nullable()->constrained('training_categories')->nullOnDelete();
            $table->foreignId('local_lesson_id')->nullable()->constrained('training_lessons')->nullOnDelete();
            $table->foreignId('local_content_block_id')->nullable()->constrained('training_content_blocks')->nullOnDelete();
            $table->foreignId('video_asset_id')->nullable()->constrained('video_assets')->nullOnDelete();

            // Raw payload from Kartra page for debugging
            $table->json('raw_data')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kartra_imports');
    }
};
