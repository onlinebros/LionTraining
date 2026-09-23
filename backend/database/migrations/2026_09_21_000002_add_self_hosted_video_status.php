<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'self_hosted' to video_assets.vimeo_status.
 *
 * The column was written when the plan was to push every Kartra video to
 * Vimeo, so its four states are all points on that journey — pending,
 * uploading, uploaded, failed. We are not doing that: the videos are served
 * from our own storage behind the membership check, because a Vimeo URL is
 * playable by anyone it is passed to and outlives the subscription that paid
 * for it.
 *
 * Without a state for that, every self-hosted asset sits at 'pending' and the
 * admin video library reads as 187 videos waiting to be uploaded — a queue
 * nobody is ever going to work through. 'self_hosted' says the asset has
 * arrived rather than that it is stuck.
 *
 * The column keeps its name. Renaming it touches the Vimeo upload service, the
 * admin screens and the existing rows for no behavioural gain, and the upload
 * path still works for anyone who wants it later.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'video_assets_vimeo_status_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // enum widening is a Postgres check constraint concern
        }

        DB::statement('ALTER TABLE video_assets DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE video_assets ADD CONSTRAINT ' . self::CONSTRAINT . ' CHECK (vimeo_status::text = ANY (ARRAY['
            . "'pending'::character varying, 'uploading'::character varying, 'uploaded'::character varying,"
            . " 'failed'::character varying, 'self_hosted'::character varying]::text[]))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Anything self-hosted goes back to 'pending', the only state the
        // original constraint had for "not on Vimeo".
        DB::table('video_assets')->where('vimeo_status', 'self_hosted')->update(['vimeo_status' => 'pending']);

        DB::statement('ALTER TABLE video_assets DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE video_assets ADD CONSTRAINT ' . self::CONSTRAINT . ' CHECK (vimeo_status::text = ANY (ARRAY['
            . "'pending'::character varying, 'uploading'::character varying, 'uploaded'::character varying,"
            . " 'failed'::character varying]::text[]))"
        );
    }
};
