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
        // What the customer reads on the invoice and the receipt. It is a
        // Training Program, never a membership (owner, 2026-09-22): the member
        // account is free and this is the optional paid part.
        'name'      => env('STRIPE_PRODUCT_NAME', 'Q3 Training Program'),
        'amount'    => (int) env('STRIPE_PRICE_AMOUNT', 19900),   // minor units
        'currency'  => env('STRIPE_CURRENCY', 'usd'),
        'interval'  => env('STRIPE_PRICE_INTERVAL', 'year'),

        // There is no free trial. "Recover my genius now" partners are charged
        // the day the training program opens (QL_PRELAUNCH_ENDS_AT), or at
        // sign-up once it is open. The "trial" in the code is only the Stripe
        // mechanism that holds the card until then.

        /*
        | "Wait for my commissions" partners are held until their paid
        | commission payouts add up to this many dollars. There is no cutoff: a
        | partner who never reaches it is never charged.
        */
        'commission_threshold' => (float) env('STRIPE_COMMISSION_BILLING_THRESHOLD', 200),

        /*
        | Stripe will not park a trial more than two years out, so a commission
        | hold is parked this far ahead and `billing:commission-holds` pushes it
        | back out whenever it gets within `commission_hold_renew_days`.
        */
        'commission_hold_days'       => (int) env('STRIPE_COMMISSION_HOLD_DAYS', 700),
        'commission_hold_renew_days' => (int) env('STRIPE_COMMISSION_HOLD_RENEW_DAYS', 90),

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

        // A bank account already attached to another partner's payout account
        // is refused during onboarding.
        'enforce_connect_uniqueness' => (bool) env('STRIPE_ENFORCE_CONNECT_UNIQUENESS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connect: partner payouts
    |--------------------------------------------------------------------------
    |
    | Partners set up a connected account from the Get Paid page, inside Stripe's
    | embedded components, and commission payouts are sent to it as transfers
    | from the Q3 balance. See App\Services\Stripe\StripeConnectService.
    |
    */

    'connect' => [
        'enabled'         => (bool) env('STRIPE_CONNECT_ENABLED', true),
        'account_country' => env('STRIPE_CONNECT_COUNTRY', 'US'),

        // What Stripe's review sees on every partner account. The company site,
        // not a partner's referral link, which does not pass Stripe's website check.
        'business_url'        => env('STRIPE_CONNECT_BUSINESS_URL', 'https://q3.life'),
        'product_description' => env('STRIPE_CONNECT_PRODUCT_DESCRIPTION', 'Referral commissions from product sales and marketing through Quantum 3 Solution platform'),

        // Offer Stripe's hosted onboarding if the embedded form cannot load.
        'hosted_fallback' => (bool) env('STRIPE_CONNECT_HOSTED_FALLBACK', true),

        // Who collects KYC requirements.
        //   application: we do. Onboarding stays inside our page with no Stripe
        //                sign-in pop-up; partners have no Stripe dashboard and
        //                manage their bank on Get Paid; we carry fees and losses.
        //   stripe:      Stripe does, with an Express dashboard, but its embedded
        //                form then opens a sign-in pop-up we cannot suppress.
        // Immutable per account: changing it only affects accounts created
        // afterwards (see `connect:reset-account`).
        'requirement_collection' => env('STRIPE_CONNECT_REQUIREMENT_COLLECTION', 'application'),

        // Have Stripe collect filing-grade tax details and file partners' 1099s.
        // Stripe treats the capability as permanent once requested.
        'tax_reporting' => (bool) env('STRIPE_CONNECT_TAX_REPORTING', true),

        // Who pays Stripe's Connect fees and covers negative balances. Must both
        // be `application` when requirement_collection is `application`.
        'fees_payer'   => env('STRIPE_CONNECT_FEES_PAYER', 'application'),
        'losses_payer' => env('STRIPE_CONNECT_LOSSES_PAYER', 'application'),
    ],

];
