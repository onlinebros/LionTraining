<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * S3-compatible object storage (DigitalOcean Spaces) for the screen
         * recording library — videos are too large for the droplet's disk.
         * Objects are private: playback goes through a signed, time-limited
         * URL minted after the app has checked who is asking. 'throw' is on so
         * a failed write cannot leave a recording row pointing at nothing.
         *
         * Falls back to the AWS_* values, so one set of credentials serves
         * both disks unless SPACES_* point recordings at a different bucket.
         * With no bucket at all, config/screen-recordings.php uses the local
         * public disk instead.
         */
        'spaces' => [
            'driver' => 's3',
            'key' => env('SPACES_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('SPACES_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('SPACES_REGION', env('AWS_DEFAULT_REGION', 'nyc3')),
            'bucket' => env('SPACES_BUCKET', env('AWS_BUCKET')),
            'url' => env('SPACES_URL', env('AWS_URL')),
            'endpoint' => env('SPACES_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
