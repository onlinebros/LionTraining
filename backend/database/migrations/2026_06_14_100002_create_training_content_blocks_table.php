<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_content_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->enum('type', ['video', 'text', 'download']);
            $table->string('title')->nullable();
            // Video
            $table->string('video_url')->nullable();
            $table->string('video_provider')->nullable(); // youtube, vimeo, file
            // Rich text
            $table->longText('body')->nullable();
            // Download
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('file_mime')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_content_blocks');
    }
};
