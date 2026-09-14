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
        Schema::table('kartra_imports', function (Blueprint $table) {
            $table->text('page_content')->nullable()->after('kartra_description');   // plain text
            $table->mediumText('page_html')->nullable()->after('page_content');      // cleaned HTML
            $table->string('page_title')->nullable()->after('kartra_title');         // actual page <title>
        });
    }

    public function down(): void
    {
        Schema::table('kartra_imports', function (Blueprint $table) {
            $table->dropColumn(['page_content', 'page_html', 'page_title']);
        });
    }
};
