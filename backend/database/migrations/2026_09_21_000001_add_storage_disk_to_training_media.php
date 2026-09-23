<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a piece of training media say which disk it lives on.
 *
 * The Kartra extract landed on the droplet's private disk, and production
 * serves it from the Space. Both will be true at once during the transfer, so
 * the disk cannot be a global setting read at playback time — a half-finished
 * upload would point every unmoved video at a bucket that does not hold it yet.
 *
 * So the disk is written onto the row when the bytes are placed, exactly as
 * ScreenRecording does it. `training:publish-media` flips `disk` and
 * `storage_path` per asset, in the same statement that confirms the object
 * exists, which makes the transfer resumable and safe to run twice.
 *
 * Null means "wherever config/training.php points", which is what every row
 * seeded before the transfer says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_assets', function (Blueprint $table) {
            $table->string('disk')->nullable()->after('local_filename');
            // Object keys are longer than the 255 a local relative path needs.
            $table->string('storage_path', 500)->nullable()->after('disk');
            $table->timestamp('published_at')->nullable()->after('storage_path');
        });

        Schema::table('training_content_blocks', function (Blueprint $table) {
            $table->string('file_disk')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('video_assets', function (Blueprint $table) {
            $table->dropColumn(['disk', 'storage_path', 'published_at']);
        });

        Schema::table('training_content_blocks', function (Blueprint $table) {
            $table->dropColumn('file_disk');
        });
    }
};
