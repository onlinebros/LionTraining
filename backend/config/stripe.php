<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | `key` is the publishable key handed to the browser; `secret` never leaves
    | the server. The API version is pinned here rather than left to the
    | account default so a dashboard-side version bump cannot silently change
    | the shape of the objects this application parses.
    |
    */

    'key'         => env('STRIPE_KEY'),
    'secret'      => env('STRIPE_SECRET'),
    'api_version' => env('STRIPE_API_VERSION', '2025-08-27.basil'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Two endpoints with two secrets. Platform-account events and connected-
    | account events (payouts) arrive separately and must each be verified
    | against their own secret — verifying a Connect event against the platform
    | secret has to fail, or the split is decorative.
    |
    */

    'webhook_secret'         => env('STRIPE_WEBHOOK_SECRET'),
    'webhook_connect_secret' => env('STRIPE_WEBHOOK_CONNECT_SECRET'),
    'webhook_tolerance'      => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | The subscription product
    |--------------------------------------------------------------------------
    |
    | Created by `php artisan billing:bootstrap-product`, which prints the ids
    | to paste in here — a new environment is set up by a command rather than by
    | clicking around a dashboard.
    |
    */

    'subscription' => [
        'product_id' => env('STRIPE_PRODUCT_ID'),
        'price_id'   => env('STRIPE_PRICE_ID'),

        // Only used by billing:bootstrap-product when creating the price.
        'name'      => env('STRIPE_PRODUCT_NAME', 'Quantum Life Partner Membership'),
        'amount'    => (int) env('STRIPE_PRICE_AMOUNT', 19900),   // minor units
        'currency'  => env('STRIPE_CURRENCY', 'usd'),
        'interval'  => env('STRIPE_PRICE_INTERVAL', 'year'),

        // Trial granted to anyone signing up after pre-launch has ended.
        'trial_days' => (int) env('STRIPE_TRIAL_DAYS', 30),

        /*
        | Pre-launch signups park on a placeholder trial this far out, because
        | the launch date is not known when they sign up. `billing:apply-prelaunch-end`
        | rolls every parked trial onto the real schedule once the date is set.
        |
        | Without this, a pre-launch signup would either be charged immediately
        | (they were promised they would not be) or need its subscription
        | created retroactively on launch day for thousands of people at once.
        */
        'prelaunch_placeholder_days' => (int) env('STRIPE_PRELAUNCH_PLACEHOLDER_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access grace period
    |--------------------------------------------------------------------------
    |
    | How long a `past_due` subscription keeps access after its period ends.
    | This is a deliberate policy value, not an accident of which statuses the
    | gate happens to list: a card that fails on renewal should not lock someone
    | out the same hour, but it must lock them out eventually.
    |
    */

    'grace_days' => (int) env('STRIPE_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Safeguards
    |--------------------------------------------------------------------------
    |
    | Card uniqueness stops the same physical card backing two accounts. Enforced
    | in the application for a friendly error and by a unique index for the race.
    | Off by default so an existing dataset with duplicates can be cleaned first.
    |
    */

    'safeguards' => [
        'enforce_card_uniqueness' => (bool) env('STRIPE_ENFORCE_CARD_UNIQUENESS', true),
    ],

];
