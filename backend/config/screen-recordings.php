<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Where finished recordings land. Defaults to the DigitalOcean Space when
    | its credentials are present and falls back to the local public disk for
    | development, so a dev box needs no object-storage credentials to exercise
    | the recorder end to end.
    |
    | The disk name is copied onto every recording row at upload time. Changing
    | this setting therefore only affects NEW recordings — existing ones keep
    | resolving against the disk they were written to.
    |
    */

    'disk' => env('RECORDINGS_DISK')
        ?: ((env('SPACES_BUCKET') ?: env('AWS_BUCKET')) ? 'spaces' : 'public'),

    /*
    |--------------------------------------------------------------------------
    | Object key prefixes
    |--------------------------------------------------------------------------
    */

    'path'           => env('RECORDINGS_PATH', 'training-recordings'),
    'thumbnail_path' => env('RECORDINGS_THUMBNAIL_PATH', 'training-recordings/thumbnails'),

    /*
    | Half-uploaded captures are assembled here on the local disk before being
    | streamed to the Space in one pass. Relative to storage/app.
    */
    'temp_path' => 'recording-uploads',

    /*
    |--------------------------------------------------------------------------
    | Chunked upload
    |--------------------------------------------------------------------------
    |
    | The browser uploads the capture in pieces while it is still recording, so
    | a two-hour screen share never has to fit in a single request. The ceiling
    | below is clamped down at runtime to whatever PHP's upload_max_filesize and
    | post_max_size actually allow (see RecordingStorage::chunkBytes()), so this
    | value is a preference, not a promise.
    |
    | nginx's client_max_body_size must be at least the effective chunk size,
    | plus room for the multipart envelope. 8m is a comfortable setting.
    |
    */

    'chunk_bytes' => (int) env('RECORDINGS_CHUNK_BYTES', 8 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    // 5 GB. Large enough for a long uploaded recording; note the assembled
    // file also sits on the droplet's disk for the length of the upload, so
    // several concurrent 5 GB uploads want watching.
    'max_bytes'            => (int) env('RECORDINGS_MAX_BYTES', 5 * 1024 * 1024 * 1024),
    // How long the studio will record before stopping itself. Uploads are not
    // bound by this — their length is whatever ffprobe measures.
    'max_duration_seconds' => (int) env('RECORDINGS_MAX_DURATION', 7200),

    /*
    |--------------------------------------------------------------------------
    | Signed playback links
    |--------------------------------------------------------------------------
    |
    | How long a minted object URL stays valid. Long enough to watch a long
    | training video without the link dying mid-playback, short enough that a
    | copied URL is not a lasting bypass of the access checks.
    |
    */

    'link_ttl_minutes' => (int) env('RECORDINGS_LINK_TTL', 180),

    /*
    |--------------------------------------------------------------------------
    | Abandoned upload cleanup
    |--------------------------------------------------------------------------
    |
    | A tab closed mid-recording leaves a partial file on the droplet and an
    | 'uploading' row in the database. recordings:prune sweeps both after this
    | many hours.
    |
    */

    'stale_upload_hours' => (int) env('RECORDINGS_STALE_UPLOAD_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Trimming
    |--------------------------------------------------------------------------
    |
    | Cutting a recording to a start/end range is a re-encode, not a stream
    | copy. Stream copy is instant but can only cut on a keyframe, which puts
    | "remove the first ten seconds" wherever the nearest keyframe happens to
    | land — often mid-word. The re-encode is exact, runs on the queue, and
    | outputs H.264/AAC MP4, which also fixes the missing-duration problem the
    | browser's WebM recordings have.
    |
    | 'nice' keeps a long encode from competing with PHP-FPM on a two-core
    | droplet. Set it to null to run at normal priority.
    |
    */

    'trim' => [
        'ffmpeg'    => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe'   => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
        'preset'    => env('RECORDINGS_TRIM_PRESET', 'veryfast'),
        'crf'       => (int) env('RECORDINGS_TRIM_CRF', 23),
        'timeout'   => (int) env('RECORDINGS_TRIM_TIMEOUT', 3600),
        'nice'      => env('RECORDINGS_TRIM_NICE', 10),
        'work_path' => 'recording-trims',
    ],

    /*
    |--------------------------------------------------------------------------
    | Combining recordings
    |--------------------------------------------------------------------------
    |
    | Joining clips means re-encoding each one to a shared resolution, frame
    | rate and audio layout first — they are never recorded the same. That is
    | one encode per clip, so the timeout below is per clip rather than for the
    | whole job. Encode settings are shared with 'trim' above.
    |
    */

    'compose' => [
        'clip_timeout' => (int) env('RECORDINGS_COMPOSE_CLIP_TIMEOUT', 1800),
        'max_clips'    => (int) env('RECORDINGS_COMPOSE_MAX_CLIPS', 12),
        'work_path'    => 'recording-composes',
    ],

];
