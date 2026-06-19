<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();

            // Where the video came from
            $table->string('source')->default('kartra'); // kartra | upload | url
            $table->string('source_url', 2000)->nullable();
            $table->string('source_id')->nullable();

            // Local storage
            $table->string('local_path')->nullable();      // relative path in storage disk
            $table->string('local_filename')->nullable();
            $table->bigInteger('file_size')->nullable();   // bytes
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_path')->nullable();

            // Vimeo upload tracking
            $table->enum('vimeo_status', ['pending', 'uploading', 'uploaded', 'failed'])->default('pending');
            $table->string('vimeo_video_id')->nullable();
            $table->string('vimeo_uri')->nullable();
            $table->string('vimeo_url', 500)->nullable();
            $table->string('vimeo_embed_url', 500)->nullable();
            $table->string('vimeo_privacy')->nullable()->default('disable'); // disable = domain-level only
            $table->text('vimeo_upload_error')->nullable();
            $table->timestamp('vimeo_uploaded_at')->nullable();

            // Link back to training content (set after mapping)
            $table->foreignId('content_block_id')->nullable()->constrained('training_content_blocks')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_assets');
    }
};
