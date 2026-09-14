<?php

/*
|--------------------------------------------------------------------------
| Capped sales promotions on vendor products
|--------------------------------------------------------------------------
|
| A promotion counts confirmed sales of one product, in the order the vendor
| confirmed payment, until `cap` places are filled. Standings are computed from
| the orders themselves (App\Services\Vendor\PromotionTracker), so a refund
| frees its place and the next sale moves up without anything to keep in step.
|
| Who a sale counts for follows the order's attribution: a customer sale counts
| for the partner whose link it came through, and a partner's own purchase
| counts for that partner. The commission on an own purchase still goes to
| their sponsor.
|
| Partners see the first promotion that is enabled and not yet ended.
|
*/

return [

    'promotions' => [

        'plasmaguard-pro-first-100' => [
            'name'    => 'First 100 PlasmaGuard PRO Systems',
            'summary' => 'We are tracking the first 100 PlasmaGuard PRO In-Duct Systems sold through partners, '
                .'whether a partner bought one for themselves or sold it to a customer.',

            'enabled' => (bool) env('PROMO_PG_PRO_100_ENABLED', true),

            'vendor'  => 'plasmaguard',
            'product' => 'pro-in-duct',
            'cap'     => (int) env('PROMO_PG_PRO_100_CAP', 100),

            // 'units'  — an order for three systems fills three places (owner, 2026-09-14).
            // 'orders' — one place per order, whatever the quantity.
            'counts'  => 'units',

            /*
            | In the promotion's timezone. Sales confirmed before the start are
            | not counted, which keeps earlier test orders out. Leave ends_at
            | empty to run until the cap is reached.
            */
            'starts_at' => env('PROMO_PG_PRO_100_STARTS_AT', '2026-09-14 00:00:00'),
            'ends_at'   => env('PROMO_PG_PRO_100_ENDS_AT'),
            'timezone'  => 'America/New_York',
        ],

    ],

];
