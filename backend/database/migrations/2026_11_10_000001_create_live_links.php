<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Live links: short addresses on the company site (q3.life/live-zoom) that
 * redirect to wherever staff point them today: a Zoom room, a webinar, a
 * stream. The address goes on flyers and in texts once; only the destination
 * changes. The holding spots are created empty, so they exist on every
 * environment and show the "nothing live right now" page until filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_links', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('label', 100);
            $table->string('destination_url', 2000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('live_links')->insert(array_map(fn ($row) => [
            'slug' => $row[0], 'label' => $row[1], 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], [
            ['live-zoom',     'Live Zoom call'],
            ['live-call',     'Live call'],
            ['live-training', 'Live training'],
            ['live-event',    'Live event'],
            ['live-webinar',  'Live webinar'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('live_links');
    }
};
