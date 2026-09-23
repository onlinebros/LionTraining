<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who can reach the training library
    |--------------------------------------------------------------------------
    |
    | 'admin' hides the whole library from everybody but administrators: the
    | nav link disappears, every /member/training route 404s, and no byte of
    | video or worksheet is served. It is the setting production ships with, so
    | the Kartra material can be loaded, checked and corrected on the live site
    | without a paying member seeing a half-built library.
    |
    | 'members' opens it to members under the usual subscription and drip rules.
    |
    | The database value (site setting `training_visibility`) wins when it is
    | set, so the library is opened from the admin screen rather than by a
    | deploy. This env value is the fallback for a fresh install.
    |
    */

    'visibility' => env('TRAINING_VISIBILITY', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Where training videos and worksheets live. Defaults to the DigitalOcean
    | Space once its credentials are present, and falls back to the private
    | local disk — which is where the Kartra extract already sits — so a dev box
    | needs no object-storage credentials to exercise the library end to end.
    |
    | Like recordings, the disk is copied onto each row when the bytes are
    | placed. Changing this only affects media published afterwards; anything
    | already stored keeps resolving against the disk it was written to.
    |
    | Note this is the PRIVATE local disk, not 'public'. Training video is the
    | product. It must never be reachable by guessing a URL under /storage.
    |
    */

    'disk' => env('TRAINING_DISK')
        ?: ((env('SPACES_BUCKET') ?: env('AWS_BUCKET')) ? 'spaces' : 'local'),

    /*
    |--------------------------------------------------------------------------
    | Object key prefixes
    |--------------------------------------------------------------------------
    |
    | Where the Kartra extract was downloaded to, and where it is published to.
    | The source paths are what kartra:import and kartra:content wrote; leave
    | them alone unless those commands change.
    |
    */

    'paths' => [
        'source_videos' => 'kartra-videos',
        'source_files'  => 'kartra-files',
        'videos'        => env('TRAINING_VIDEO_PATH', 'training/videos'),
        'files'         => env('TRAINING_FILE_PATH', 'training/files'),
        'posters'       => env('TRAINING_POSTER_PATH', 'training/posters'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed playback links
    |--------------------------------------------------------------------------
    |
    | How long an object URL minted for a Space stays valid. Long enough to
    | watch a ninety-minute webinar replay without the link dying mid-playback,
    | short enough that a copied URL is not a lasting bypass of the access
    | checks. Only used on object-storage disks — local files are streamed by
    | the application, which re-checks access on every request.
    |
    */

    'link_ttl_minutes' => (int) env('TRAINING_LINK_TTL', 180),

    /*
    |--------------------------------------------------------------------------
    | Streaming from the local disk
    |--------------------------------------------------------------------------
    |
    | A browser seeking inside an MP4 sends a Range header, and a response that
    | ignores it makes the scrub bar dead — the player can only play from zero.
    | So local playback is served by our own range handler, which reads the file
    | in chunks of this size rather than holding a 400 MB video in memory.
    |
    */

    'stream_chunk_bytes' => (int) env('TRAINING_STREAM_CHUNK', 512 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Release schedule
    |--------------------------------------------------------------------------
    |
    | The drip the seeder lays down: the numbered teaching modules open one per
    | month from the start of the member's paid membership, and the reference
    | material — webinar replays, Media Center, Inspiration, Nature, Science —
    | is open from day one.
    |
    | This is the STARTING POINT only. Every delay is editable per category and
    | per lesson in the admin afterwards, and re-seeding is not needed to change
    | one. 'always_open' is matched against the Kartra module title.
    |
    */

    'drip' => [
        // Month 0 is the first module; each subsequent teaching module adds one.
        'unit'            => 'months',
        'step'            => 1,
        'always_open'     => [
            'Live Webinars Schedule',
            'Live Webinar Replays',
            '2024 Live Webinar Replays',
            '2025 Live Webinar Replays',
            'Media Center',
            'Inspiration',
            'Movies',
            'Nature',
            'Peak Performance',
            'Science',
        ],
    ],

];
