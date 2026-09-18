<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_recordings', function (Blueprint $table) {
            $table->id();

            // Public identifier. Playback and share links are addressed by this
            // rather than the auto-increment id so a link can be handed out
            // without also handing out the size of the library.
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('training_categories')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Where the object actually lives. Stored per row so changing the
            // configured disk later cannot orphan what is already uploaded.
            $table->string('disk');
            $table->string('path')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // How the capture was composed: screen only, screen with the webcam
            // baked into a corner, or webcam only.
            $table->string('source')->default('screen');
            $table->string('webcam_position')->nullable(); // top-left|top-right|bottom-left|bottom-right
            $table->boolean('has_mic_audio')->default(false);
            $table->boolean('has_system_audio')->default(false);

            // uploading → ready, or failed if the finalize step could not place
            // the object. bytes_received lets an interrupted upload resume.
            $table->string('status')->default('uploading');
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->text('upload_error')->nullable();

            // admins | members | role | link
            $table->string('visibility')->default('admins');
            $table->foreignId('required_role_id')->nullable()->constrained('roles')->nullOnDelete();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['is_published', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_recordings');
    }
};
