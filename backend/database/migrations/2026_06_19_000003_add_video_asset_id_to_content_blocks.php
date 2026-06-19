<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_content_blocks', function (Blueprint $table) {
            $table->foreignId('video_asset_id')
                  ->nullable()
                  ->after('video_provider')
                  ->constrained('video_assets')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_content_blocks', function (Blueprint $table) {
            $table->dropForeign(['video_asset_id']);
            $table->dropColumn('video_asset_id');
        });
    }
};
