<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('release_delay')->nullable()->after('sort_order');
            $table->string('release_delay_unit', 10)->nullable()->after('release_delay'); // 'days' | 'months'
        });

        Schema::table('training_lessons', function (Blueprint $table) {
            $table->unsignedSmallInteger('release_delay')->nullable()->after('sort_order');
            $table->string('release_delay_unit', 10)->nullable()->after('release_delay');
        });
    }

    public function down(): void
    {
        Schema::table('training_categories', function (Blueprint $table) {
            $table->dropColumn(['release_delay', 'release_delay_unit']);
        });

        Schema::table('training_lessons', function (Blueprint $table) {
            $table->dropColumn(['release_delay', 'release_delay_unit']);
        });
    }
};
