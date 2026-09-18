<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trimming is non-destructive.
     *
     * `original_path` holds the untouched capture from the first trim onwards,
     * and every subsequent trim cuts from *that* rather than from the previous
     * result. Two reasons: a re-encode of a re-encode loses quality for no
     * reason, and an admin who trims to the wrong point must be able to get
     * their recording back — it cannot be captured a second time.
     */
    public function up(): void
    {
        Schema::table('screen_recordings', function (Blueprint $table) {
            $table->string('original_path')->nullable()->after('path');

            // The untrimmed length, kept so Revert restores the real duration
            // rather than the trimmed one.
            $table->unsignedInteger('original_duration_seconds')->nullable()->after('original_path');

            // Where the current file was cut from, relative to the original.
            $table->decimal('trim_start', 8, 2)->nullable()->after('original_path');
            $table->decimal('trim_end', 8, 2)->nullable()->after('trim_start');

            // Deliberately separate from `status`: a recording stays playable
            // while a trim runs, and a failed trim must not make the existing
            // file unreachable.
            $table->string('trim_status')->nullable()->after('trim_end');
            $table->text('trim_error')->nullable()->after('trim_status');
        });
    }

    public function down(): void
    {
        Schema::table('screen_recordings', function (Blueprint $table) {
            $table->dropColumn(['original_path', 'original_duration_seconds', 'trim_start', 'trim_end', 'trim_status', 'trim_error']);
        });
    }
};
