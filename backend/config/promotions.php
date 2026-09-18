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
            'summary' => 'The launch special for the first 100 PlasmaGuard PRO In-Duct Systems sold through partners, '
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

            /*
            |------------------------------------------------------------------
            | The launch special's money (owner, 2026-09-17)
            |------------------------------------------------------------------
            |
            | - Each of the first `cap` systems earns `place_amount` for the
            |   partner it counts for, on top of the normal sale commission.
            | - Each of the next `pool_units` systems puts `pool_per_unit` into a
            |   pool shared by the `cap` places, one share per place. It accrues
            |   as each sale is confirmed: $100 across 100 places is $1 a share.
            | - After cap + pool_units systems the special is over.
            |
            | Dollars, not cents: these are written straight to the commission
            | ledger, which stores dollars.
            |
            | `lock_days`: a refund inside this many days of the sale voids its
            | bonus and the next sale moves up. After that the place is final.
            | Matches PlasmaGuard's refund window (vendors.*.commission.clawback_days).
            */
            'bonus' => [
                'enabled'       => (bool) env('PROMO_PG_PRO_100_BONUS', true),
                'place_amount'  => (float) env('PROMO_PG_PRO_100_PLACE_BONUS', 500),
                'pool_units'    => (int) env('PROMO_PG_PRO_100_POOL_UNITS', 500),
                'pool_per_unit' => (float) env('PROMO_PG_PRO_100_POOL_PER_UNIT', 100),
                'lock_days'     => (int) env('PROMO_PG_PRO_100_LOCK_DAYS', 60),
            ],
        ],

    ],

];
