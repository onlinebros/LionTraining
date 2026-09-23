<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Kartra worksheet record say which disk its bytes are on.
 *
 * video_assets already carries `disk`/`storage_path`. This is the same idea for
 * files, and it is what allows production to build the library from objects
 * that are already in the Space: the 24 GB is uploaded once, from wherever it
 * happens to live, and every other environment attaches to it by name instead
 * of holding its own copy.
 *
 * Null means the local private disk, which is what the Kartra download wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kartra_files', function (Blueprint $table) {
            $table->string('disk')->nullable()->after('local_filename');
            // Object keys run longer than a local relative path.
            $table->string('local_path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('kartra_files', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
