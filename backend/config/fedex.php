<?php

/*
|--------------------------------------------------------------------------
| FedEx API
|--------------------------------------------------------------------------
|
| Credentials for OUR FedEx developer project, used to price parcels. The
| shipping ACCOUNT the rate is quoted against is a different thing and lives on
| the vendor (config/vendors.php → pricing.shipping.carrier.account_number),
| because it is theirs, not ours.
|
| Same two-set shape as the Stripe credentials: both sets present, one switch,
| anything other than the exact string 'live' resolving to sandbox.
|
| Sandbox and production are separate credentials AND separate hosts. FedEx
| rejects sandbox keys against the production host outright, which is a good
| failure — but it means the host has to follow the mode, not be hardcoded.
|
*/

$fedexKeys = [
    'test' => [
        'key'    => env('FEDEX_TEST_API_KEY'),
        'secret' => env('FEDEX_TEST_SECRET_KEY'),
        'host'   => 'https://apis-sandbox.fedex.com',
    ],
    'live' => [
        'key'    => env('FEDEX_LIVE_API_KEY'),
        'secret' => env('FEDEX_LIVE_SECRET_KEY'),
        'host'   => 'https://apis.fedex.com',
    ],
];

$fedexMode = env('FEDEX_MODE', 'test') === 'live' ? 'live' : 'test';

return [

    'mode' => $fedexMode,

    'key'    => $fedexKeys[$fedexMode]['key'],
    'secret' => $fedexKeys[$fedexMode]['secret'],
    'host'   => $fedexKeys[$fedexMode]['host'],

    'configured' => [
        'test' => filled($fedexKeys['test']['key']),
        'live' => filled($fedexKeys['live']['key']),
    ],

    /*
    | Services to price. Ground and Home Delivery are the sensible defaults for
    | a 16 lb billable carton; FedEx returns Home Delivery automatically for a
    | residential address and Ground for commercial, so asking for both and
    | taking the cheapest handles the distinction without us classifying it.
    */
    'services' => [
        'FEDEX_GROUND',
        'GROUND_HOME_DELIVERY',
    ],

    /*
    | ACCOUNT returns the negotiated rate, LIST the published one. Both are
    | requested so a quote can be compared against retail — if they come back
    | identical, the account is not actually linked and we are quoting retail
    | while believing we are not.
    */
    'rate_request_types' => ['ACCOUNT', 'LIST'],

    'pickup_type' => env('FEDEX_PICKUP_TYPE', 'USE_SCHEDULED_PICKUP'),

    /*
    | A customer is waiting on this call. FedEx sandbox has been observed
    | returning 503 for extended periods, and a checkout that hangs on someone
    | else's outage is worse than one that hands the order to a human.
    */
    'timeout' => (int) env('FEDEX_TIMEOUT', 12),

    // OAuth tokens last an hour; cached just under that.
    'token_ttl' => (int) env('FEDEX_TOKEN_TTL', 3300),
];
