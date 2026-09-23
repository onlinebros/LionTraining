<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vimeo' => [
        'access_token' => env('VIMEO_ACCESS_TOKEN'),
        'privacy'      => env('VIMEO_PRIVACY', 'disable'),
    ],

    // Cloudflare Turnstile — bot check on the public website contact form.
    // Empty allowed_hostnames skips the hostname check (test keys only).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'allowed_hostnames' => array_filter(explode(',', (string) env('TURNSTILE_ALLOWED_HOSTNAMES', ''))),
    ],

    // The Kartra portal the training library was migrated out of. Only the
    // one-off import commands use these, and they are unset in normal
    // operation — the material now lives in our own storage.
    'kartra' => [
        'url'      => env('KARTRA_PORTAL_URL'),
        'email'    => env('KARTRA_EMAIL'),
        'password' => env('KARTRA_PASSWORD'),
    ],

    // Stripe lives in config/stripe.php, not here. It outgrew a services entry
    // once it carried two webhook secrets, the product and price ids, trial
    // arithmetic and the card-uniqueness safeguard.

];
