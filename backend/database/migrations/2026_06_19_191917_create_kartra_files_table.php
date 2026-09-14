<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kartra_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kartra_import_id')->constrained('kartra_imports')->cascadeOnDelete();

            // Source info from Kartra
            $table->string('kartra_download_id')->nullable();     // numeric ID from /download/NNN
            $table->string('kartra_download_url', 2000)->nullable(); // full authenticated URL used
            $table->string('display_name')->nullable();           // link text shown on page
            $table->string('original_filename')->nullable();      // filename from Content-Disposition

            // Local storage
            $table->string('local_path', 1000)->nullable();
            $table->string('local_filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();

            $table->enum('status', ['pending', 'downloading', 'downloaded', 'failed'])
                  ->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('kartra_import_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kartra_files');
    }
};
