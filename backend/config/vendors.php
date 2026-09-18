<?php

/*
|--------------------------------------------------------------------------
| Third-party vendor referral registry
|--------------------------------------------------------------------------
|
| A vendor here is a company whose product our partners sell but whose
| checkout we do not own. We capture the lead, attach the partner's referral
| code, hand the customer to the vendor's own payment page, and reconcile the
| confirmation that comes back.
|
| Everything that a commercial negotiation can change — the checkout URL, the
| parameter names, the commission rate, the price shown — lives here rather
| than in code. PlasmaGuard's own IT has floated replacing their Stripe
| Payment Link with a custom EasyPost/AvaTax checkout; when that happens this
| file changes and nothing else does.
|
| See memory-bank/vendor-referral-framework.md for the design this implements.
|
*/

/*
|--------------------------------------------------------------------------
| Vendor credential sets, per mode
|--------------------------------------------------------------------------
|
| Both sets live in the environment at once and a single switch decides which
| is in play. The alternative — one set of variable names, swapped at deploy —
| means the live keys and the test keys occupy the same slot, and the only thing
| standing between a staging box and a real customer's card is whoever edited
| .env last.
|
| Built here rather than inline so the whole file stays cacheable: this is
| evaluated once when config:cache runs, and what is stored is a plain array.
|
| PLASMAGUARD_STRIPE_MODE=test on every machine except the production host.
|
*/

$plasmaguardKeys = [
    'test' => [
        'account'        => env('PLASMAGUARD_TEST_STRIPE_ACCOUNT'),
        'secret'         => env('PLASMAGUARD_TEST_STRIPE_SECRET'),
        'key'            => env('PLASMAGUARD_TEST_STRIPE_KEY'),
        'webhook_secret' => env('PLASMAGUARD_TEST_WEBHOOK_SECRET'),
    ],
    'live' => [
        'account'        => env('PLASMAGUARD_LIVE_STRIPE_ACCOUNT'),
        'secret'         => env('PLASMAGUARD_LIVE_STRIPE_SECRET'),
        'key'            => env('PLASMAGUARD_LIVE_STRIPE_KEY'),
        'webhook_secret' => env('PLASMAGUARD_LIVE_WEBHOOK_SECRET'),
    ],
];

/*
| Anything other than the exact string 'live' is test. A typo in the mode
| variable must fail safe — towards the sandbox, never towards real money.
*/
$plasmaguardMode = env('PLASMAGUARD_STRIPE_MODE', 'test') === 'live' ? 'live' : 'test';

return [

    /*
    |--------------------------------------------------------------------------
    | Reference prefix
    |--------------------------------------------------------------------------
    |
    | Every lead gets a public reference built as PREFIX-XXXXXXXXXX. It is the
    | value we pass as `client_reference_id`, so it must stay inside Stripe's
    | allowed charset for that field: letters, digits, dashes and underscores,
    | 200 characters maximum. Changing the prefix does not invalidate existing
    | references — they are stored, not recomputed.
    |
    */

    'reference_prefix' => env('VENDOR_REF_PREFIX', 'QLV'),

    /*
    |--------------------------------------------------------------------------
    | Vendors
    |--------------------------------------------------------------------------
    */

    'vendors' => [

        'plasmaguard' => [

            'name'         => 'PlasmaGuard',
            'legal_name'   => 'PlasmaGuard LLC',
            'website'      => 'https://plasmaguard.com/',
            'support_email' => env('PLASMAGUARD_SUPPORT_EMAIL', 'info@plasmaguard.com'),

            /*
            | Their order desk, emailed the details of every confirmed sale so
            | they can fulfil it (VendorOrderPlaced). Sent only while the
            | vendor's Stripe is in live mode: a sandbox order reaching a real
            | order desk is a $6,000 system shipped for free. Empty disables it.
            */
            'order_email'  => env('PLASMAGUARD_ORDER_EMAIL', 'orders@plasmaguard.com'),

            // Whether partners can share these pages at all. A vendor whose
            // terms are still being negotiated is registered but switched off,
            // so the pages exist to demo without being publicly shareable.
            'enabled'      => (bool) env('PLASMAGUARD_ENABLED', true),

            /*
            | PlasmaGuard is the merchant of record: they charge the card, owe
            | the sales tax, and ship. We are a referral channel and never
            | touch card data. Rendered in the page footer as a disclosure —
            | it is a legal position, not a decoration.
            */
            'merchant_of_record' => true,

            /*
            |------------------------------------------------------------------
            | Checkout handoff
            |------------------------------------------------------------------
            |
            | `url` is the vendor's hosted payment page. `params` maps our
            | internal field names onto whatever query parameters that page
            | accepts — the names on the right are Stripe Payment Link's; a
            | different checkout means editing this map, not the service.
            |
            | A param mapped to null is simply not sent, which is how a vendor
            | that cannot accept a field is expressed.
            |
            */

            'checkout' => [
                /*
                | 'direct'       — we build the charge on the vendor's Stripe
                |                  account with their restricted key. Chosen
                |                  2026-09-09: they fulfil and collect, we record
                |                  what we are owed and invoice outside Stripe.
                | 'payment_link' — the original handoff to their hosted page.
                |                  Kept because it needs nothing from them and is
                |                  the fallback if the keys are ever withdrawn.
                */
                'mode'   => env('PLASMAGUARD_CHECKOUT_MODE', 'direct'),

                'url'    => env('PLASMAGUARD_CHECKOUT_URL', 'https://buy.stripe.com/cNi3cv4zF1tE4RBfYY5J600'),
                'params' => [
                    'reference' => 'client_reference_id',
                    'email'     => 'prefilled_email',
                ],
            ],

            /*
            |------------------------------------------------------------------
            | The vendor's own Stripe credentials
            |------------------------------------------------------------------
            |
            | A RESTRICTED key belonging to PlasmaGuard, scoped by them to
            | payments, customers and tax. Verified 2026-09-09 as having no
            | access to their balance, payouts or charge history.
            |
            | Charges built with this land on their account and their books. We
            | take nothing at the point of sale — our share is recorded on the
            | order and invoiced to them separately.
            |
            | Not to be confused with config/stripe.php, which is our own
            | account for memberships.
            |
            */

            'stripe' => $plasmaguardKeys[$plasmaguardMode] + [
                /*
                | Which key set is live on this machine. Surfaced so every screen
                | that can take a payment can say so — a staging box quietly in
                | live mode is the failure that costs a real customer real money.
                */
                'mode' => $plasmaguardMode,

                // Whether the other set is even present, so the admin screen can
                // say "live keys not yet installed" rather than just "test".
                'configured' => [
                    'test' => filled($plasmaguardKeys['test']['secret']),
                    'live' => filled($plasmaguardKeys['live']['secret']),
                ],

                /*
                | Their Stripe Tax computes tax at charge time from the shipping
                | address, using THEIR registrations. Confirmed 2026-09-09: only
                | Michigan is registered, so every other state returns zero. That
                | is Stripe behaving correctly, not a fault — but it means the
                | figure is theirs and their liability, and we record it rather
                | than assert it.
                */
                'automatic_tax' => (bool) env('PLASMAGUARD_AUTOMATIC_TAX', true),
            ],

            /*
            |------------------------------------------------------------------
            | Confirmation
            |------------------------------------------------------------------
            |
            | `webhook_secret` is the signing secret from the VENDOR's Stripe
            | account, not ours. Until they provide it the endpoint answers 503
            | rather than accepting unsigned posts, and conversions are marked
            | by hand from the admin screen. That is the designed fallback, not
            | a broken state.
            |
            | `match_window_days` bounds the email fallback used when a payload
            | arrives with no reference: only leads handed off within that many
            | days are candidates. Unbounded matching eventually attributes a
            | stranger's purchase to whoever once entered that email address.
            |
            */

            // Mode-aware: the sandbox endpoint and the live endpoint sign with
            // different secrets, and verifying against the wrong one fails closed.
            'webhook_secret'    => $plasmaguardKeys[$plasmaguardMode]['webhook_secret'],
            'match_window_days' => (int) env('PLASMAGUARD_MATCH_WINDOW_DAYS', 30),

            /*
            | The backstop for a webhook that never arrived. Every fifteen
            | minutes the scheduler reads the vendor's event log with their key
            | and records anything the endpoint missed — see VendorEventSync.
            |
            | `since` is required and nothing is fetched until it is set. It is
            | the moment recovery is trusted from: set it to when the webhook
            | went live, so an order paid before then (the $1 live test) is
            | never replayed into a sale with a commission attached.
            |
            | `grace_minutes` leaves the newest events to the webhook, so an
            | order confirmed by reconciliation means delivery really failed.
            */
            'event_sync' => [
                'enabled'        => (bool) env('PLASMAGUARD_EVENT_SYNC', true),
                'since'          => env('PLASMAGUARD_EVENT_SYNC_SINCE'),
                'lookback_hours' => (int) env('PLASMAGUARD_EVENT_SYNC_LOOKBACK_HOURS', 72),
                'grace_minutes'  => (int) env('PLASMAGUARD_EVENT_SYNC_GRACE_MINUTES', 10),
            ],

            /*
            |------------------------------------------------------------------
            | Revenue share — what the VENDOR pays US
            |------------------------------------------------------------------
            |
            | PlasmaGuard confirmed (2026-09-09): $3,000 to us on a $6,000
            | system. A 50/50 split of the product price, not a referral
            | percentage — closer to a reseller margin, and the accountant should
            | know that before year end.
            |
            | Under Connect this is taken at source as the `application_fee_amount`
            | on the charge, so it lands in our platform balance the moment the
            | customer pays. Nothing to invoice and nothing to chase.
            |
            | `basis` is product_subtotal and must stay that way. Our share is of
            | the goods, never of the shipping (the carrier's money), the handling
            | fee, or the tax (the state's money). Taking a cut of collected sales
            | tax is not a rounding error, it is someone else's liability.
            |
            */

            'revenue_share' => [
                /*
                | 'per_unit' — a fixed amount per item sold, set on the product.
                | 'rate'     — a fraction of the product subtotal.
                |
                | PlasmaGuard is per_unit: a flat $3,000 for each system, so
                | three systems is $9,000. At the current $6,000 price that
                | happens to equal 50%, which is exactly why the distinction has
                | to be recorded now — the two models only agree while the price
                | is unchanged, and they will diverge the first time anything is
                | discounted.
                |
                | The amount itself lives on the product, not here: it is a
                | per-SKU commercial term, and a second product will have its own.
                */
                'model' => env('PLASMAGUARD_REVENUE_SHARE_MODEL', 'per_unit'),

                // Used only when model is 'rate'.
                'rate'  => (float) env('PLASMAGUARD_REVENUE_SHARE_RATE', 0.50),
                'basis' => 'product_subtotal',

                /*
                | Who absorbs Stripe's processing fee. With Connect direct
                | charges the connected account pays it by default, computed on
                | the FULL charge — product, shipping, handling and tax. On a
                | $6,612 order that is roughly $190, all of it out of
                | PlasmaGuard's half while ours stays whole.
                |
                | They will notice. Agree it in writing before the first live
                | order, not after the first statement.
                */
                'stripe_fee_payer' => env('PLASMAGUARD_STRIPE_FEE_PAYER', 'vendor'),  // vendor | platform

                /*
                | A refund does NOT return the application fee automatically.
                | On a 50/50 split that is $3,000 leaving our balance, so the
                | policy has to be explicit rather than discovered.
                */
                'refund_application_fee' => (bool) env('PLASMAGUARD_REFUND_APP_FEE', true),
            ],

            /*
            |------------------------------------------------------------------
            | Commission — what WE pay the PARTNER
            |------------------------------------------------------------------
            |
            | Paid out of our share, not out of the order. Two different numbers
            | that were previously one: a partner earning 10% earns it on our
            | $3,000, not on the customer's $6,000, and conflating them
            | overpays by six times.
            |
            | `clawback_days` mirrors the vendor's own refund window. Set it
            | shorter than theirs and we pay out on orders they can still
            | refund; the difference comes out of our pocket.
            |
            | `basis` is honoured by VendorReferralService::raiseCommission() from
            | 2026-09-14. Before that it computed against the order total, so
            | credits raised earlier are 10% of the whole charge.
            |
            | Who is paid is decided per order (App\Services\Vendor\
            | PurchaseAttribution): the partner whose link made the sale, or,
            | when a partner bought for themselves, that partner's sponsor.
            |
            */

            'commission' => [
                'rate'          => (float) env('PLASMAGUARD_COMMISSION_RATE', 0.10),
                'basis'         => env('PLASMAGUARD_COMMISSION_BASIS', 'revenue_share'),  // revenue_share | order_total
                'clawback_days' => (int) env('PLASMAGUARD_CLAWBACK_DAYS', 60),
                'plan_key'      => env('PLASMAGUARD_COMMISSION_PLAN'),
            ],

            /*
            |------------------------------------------------------------------
            | Order pricing
            |------------------------------------------------------------------
            |
            | What we are responsible for computing before the charge is created.
            | PlasmaGuard confirmed (2026-09-08): tax is handled by Stripe Tax on
            | their account; we determine shipping and add a flat processing fee
            | on top of it.
            |
            |     total = subtotal + shipping + handling + tax
            |
            | Tax is NOT in this block on purpose. Stripe computes it from the
            | shipping address at charge time and it is their liability — a
            | number of ours sitting next to it would only ever be a second
            | opinion nobody should act on.
            |
            */

            'pricing' => [

                'shipping' => [
                    // 'live'  — rate the parcel against a carrier at checkout
                    // 'flat'  — a fixed amount, for a vendor with no rate feed
                    // 'free'  — absorbed into the product price
                    'mode' => env('PLASMAGUARD_SHIPPING_MODE', 'live'),

                    // Per carton, minor units. Used when mode is 'flat', and as
                    // the agreed fallback when the carrier is unreachable.
                    'flat_amount' => (int) env('PLASMAGUARD_SHIPPING_FLAT', 0),

                    /*
                    | Carrier outage behaviour. An unlinked account still refuses
                    | — that is a misconfiguration worth surfacing. This covers
                    | only FedEx being unreachable, where refusing every sale for
                    | the duration punishes the customer for someone else's
                    | downtime. The fallback quote is labelled `flat`, never
                    | passed off as a carrier rate.
                    */
                    'fallback_on_outage' => (bool) env('PLASMAGUARD_SHIPPING_FALLBACK', true),

                    /*
                    | PlasmaGuard's own FedEx account, so quotes use their
                    | negotiated rates rather than retail. Confirmed 2026-09-09.
                    |
                    | The account NUMBER alone does not authorise rating. FedEx
                    | requires API credentials from their developer portal, and
                    | the carrier account has to be registered with the rating
                    | provider before negotiated rates are returned. Until that
                    | is done a quote silently falls back to published rates,
                    | which are materially higher — so `require_negotiated`
                    | fails the quote rather than overcharging the customer.
                    */
                    'carrier' => [
                        'name'           => 'FedEx',
                        'account_number' => env('PLASMAGUARD_FEDEX_ACCOUNT'),
                        'easypost_id'    => env('PLASMAGUARD_CARRIER_ACCOUNT'),
                        'require_negotiated' => (bool) env('PLASMAGUARD_REQUIRE_NEGOTIATED', true),
                        'services'       => ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY'],
                    ],

                    // Ship-from. Confirmed 2026-09-09.
                    'origin' => [
                        'line1'       => env('PLASMAGUARD_ORIGIN_LINE1', '30933 Industrial Rd.'),
                        'city'        => env('PLASMAGUARD_ORIGIN_CITY', 'Livonia'),
                        'state'       => env('PLASMAGUARD_ORIGIN_STATE', 'MI'),
                        'postal_code' => env('PLASMAGUARD_ORIGIN_POSTAL', '48150'),
                        'country'     => env('PLASMAGUARD_ORIGIN_COUNTRY', 'US'),
                    ],

                    /*
                    | One unit per carton, 45 per pallet. Above this many units a
                    | parcel quote stops being sensible — 10 boxes is already
                    | ~160 lb billable and freight will beat it comfortably — so
                    | the order is routed to a human for an LTL quote instead of
                    | being quoted badly.
                    */
                    'freight_threshold_units' => (int) env('PLASMAGUARD_FREIGHT_THRESHOLD', 8),
                ],

                /*
                | The $12 processing fee.
                |
                | `basis` is the open question — per order or per unit. They said
                | "$12 for processing on top of the shipping cost", which on a
                | three-generator order is either $12 or $36. Defaulted to
                | 'order' as the reading that cannot overcharge a customer;
                | confirm before launch.
                */
                'handling' => [
                    'amount' => (int) env('PLASMAGUARD_HANDLING_AMOUNT', 1200),  // minor units
                    'basis'  => env('PLASMAGUARD_HANDLING_BASIS', 'order'),      // order | unit
                    'label'  => 'Shipping & handling',
                ],

                /*
                | Shipping and the processing fee are charged as ONE line rather
                | than two.
                |
                | Not cosmetic. Several US states tax a delivery charge in full
                | when handling is bundled into it, while taxing pure carriage
                | differently — so a separate "processing" line needs its own tax
                | treatment and gets it wrong in a way that under-collects. One
                | combined line carrying Stripe's shipping tax code is both the
                | simpler build and the conservative tax position.
                |
                | Under-collecting is PlasmaGuard's liability, not ours, which is
                | precisely why we should not hand them a structure that invites
                | it.
                */
                'combine_shipping_and_handling' => true,

                /*
                | Stripe's tax code for shipping. Applied to the combined line so
                | Stripe Tax treats it as a delivery charge rather than as a
                | second product.
                */
                'shipping_tax_code' => env('PLASMAGUARD_SHIPPING_TAX_CODE', 'txcd_92010001'),
            ],

            /*
            |------------------------------------------------------------------
            | Products
            |------------------------------------------------------------------
            |
            | Reference data describing someone else's catalogue, kept here so
            | a second product is a config entry rather than a new template.
            |
            | `price` is DISPLAY ONLY and flagged as such on the page. The
            | vendor's checkout decides what is actually charged, and the
            | confirmation payload is what we record. Any figure here that
            | disagrees with their page is a marketing bug, never a billing one.
            |
            */

            'products' => [

                'pro-in-duct' => [
                    'name'      => 'PlasmaGuard PRO In-Duct System',
                    'tagline'   => 'The best solution to destroy harmful airborne and surface pathogens',
                    'vendor_url' => 'https://plasmaguard.com/plasmaguard-pro-in-duct-system/',

                    // null = "Request pricing". Set only once PlasmaGuard
                    // confirms what the checkout link actually charges.
                    'price'     => env('PLASMAGUARD_PRO_PRICE'),
                    'currency'  => 'USD',

                    /*
                    | Our cut, per system sold — $3,000 flat, so a three-unit
                    | order pays us $9,000. Minor units.
                    |
                    | Per-SKU because it is a per-SKU commercial term. It is NOT
                    | derived from `price`: if PlasmaGuard discount the system,
                    | this figure does not move on its own, and somebody has to
                    | decide whether it should. That decision belongs to a person
                    | and a contract, not to a multiplication.
                    */
                    'revenue_share_per_unit' => (int) env('PLASMAGUARD_PRO_SHARE_PER_UNIT', 300000),

                    /*
                    | The packed carton, confirmed by PlasmaGuard 2026-09-09.
                    | The Pro Kit ships as one box containing generator, hub and
                    | sensor — one unit per box, so three units is three parcels
                    | rather than one larger one.
                    |
                    | Note the box is DIM-weighted: 12 x 12 x 15 = 2160 cu in,
                    | which at FedEx's 139 divisor bills as 16 lb against an
                    | actual 9 lb. Rate on actual weight and every quote is
                    | roughly 40% light. The rating API applies this itself, but
                    | only if it is given the dimensions — which is why they are
                    | here and not just a weight.
                    */
                    'parcel' => [
                        'length_in' => 12,
                        'width_in'  => 12,
                        'height_in' => 15,
                        'weight_lb' => 9,
                        'units_per_carton'  => 1,
                        'units_per_pallet'  => 45,
                        'contents'  => 'Generator, Hub and Sensor',
                    ],

                    'summary' => 'PlasmaGuard PRO™ combines proven non-thermal cold plasma technology with sophisticated sensors that use real-time data to ensure the best purified indoor environment for employees, visitors, and customers. It installs directly into an existing HVAC system and continuously purifies all ducts and indoor environments during normal heating, cooling, and ventilation cycles.',

                    /*
                    | Manufacturer photography, mirrored into
                    | assets/images/vendors/plasmaguard/ rather than hot-linked —
                    | see SOURCES.md there for provenance and the outstanding
                    | permission question.
                    |
                    | Only images with a transparent background are listed. A
                    | product shot on a white plate looks broken on the Q3 black,
                    | and recolouring or masking someone else's product
                    | photography is not ours to do.
                    |
                    | Blank this array and the page renders without imagery — no
                    | empty frames, no broken icons.
                    */
                    'images' => [
                        'hero' => [
                            'src'    => 'assets/images/vendors/plasmaguard/pg-pro-generator-560.webp',
                            'alt'    => 'PlasmaGuard PRO in-duct generator, shown with its ionizing cell extended',
                            'width'  => 560,
                            'height' => 560,
                        ],

                        // Mirrors the two spec blocks above, plus the hub that
                        // links them — the three boxes that actually arrive.
                        'gallery' => [
                            [
                                'src'     => 'assets/images/vendors/plasmaguard/pg-pro-generator-320.webp',
                                'alt'     => 'PlasmaGuard PRO generator unit',
                                'caption' => 'Generator',
                                'note'    => 'Mounts to the duct wall; the ionizing cell sits in the airstream.',
                                'width'   => 320,
                                'height'  => 320,
                            ],
                            [
                                'src'     => 'assets/images/vendors/plasmaguard/pg-pro-sensor_sq-320.webp',
                                'alt'     => 'PlasmaGuard PRO particulate sensor showing a live reading',
                                'caption' => 'Sensor',
                                'note'    => 'Reads PM1, PM2.5 and PM10 down to 0.3 microns, room by room.',
                                'width'   => 320,
                                'height'  => 320,
                            ],
                            [
                                'src'     => 'assets/images/vendors/plasmaguard/pg-pro-hub-sq-320.webp',
                                'alt'     => 'PlasmaGuard PRO hub',
                                'caption' => 'Hub',
                                'note'    => 'Links generators and sensors, and reports to the monitoring app.',
                                'width'   => 320,
                                'height'  => 320,
                            ],
                        ],
                    ],

                    'highlights' => [
                        'Naturally inactivates up to 99.99% of tested viruses and bacteria, including SARS-CoV-2',
                        'Neutralises airborne allergens, odours, smoke, and mould spores',
                        'Patented response technology continuously measures and monitors indoor air',
                        'Reduces particulate levels down to 0.3 microns',
                        'Installs into existing HVAC — no separate ductwork or floor space',
                    ],

                    'specs' => [
                        'Generator' => [
                            'Dimensions'        => '12 × 8.5 × 14 in (305 × 216 × 356 mm)',
                            'Weight'            => '5.0 lb (2.3 kg)',
                            'Input'             => '100–240 VAC, 50/60 Hz',
                            'Power consumption' => '14–20 W (ionizing mode)',
                            'Coverage'          => 'One generator per 5 tons of cooling, 2000 CFM, or 2000–4000 sq ft',
                            'Maximum airflow'   => '4000 CFM',
                            'Warranty'          => '5-year',
                        ],
                        'Sensor' => [
                            'Monitors'  => 'Particulates down to 0.3 microns (PM1, PM2.5, PM10)',
                            'Power'     => 'Standard wall outlet or USB cable',
                            'Warranty'  => '2-year limited',
                        ],
                    ],

                    'applications' => [
                        'Offices', 'Hospitals', 'Nursing homes', 'Airports & transit',
                        'Hotels', 'Schools', 'Apartments', 'Arenas', 'Theatres', 'Retail',
                    ],

                    /*
                    | How many units a building needs.
                    |
                    | The manufacturer's own rule: the generator installs into a
                    | system's ductwork and treats the air moving through it, so
                    | it is one per system rather than one per building. Three
                    | furnaces is three units.
                    |
                    | Config rather than copy in the template because the rule is
                    | per-product — a ductless or portable unit sizes completely
                    | differently — and because it drives arithmetic the customer
                    | sees, which should not be buried in a Blade file.
                    |
                    | It is a SUGGESTION and the page says so. PlasmaGuard
                    | confirms sizing against the real system before shipping,
                    | and a customer who knows their building better than our
                    | form does can always override it.
                    */
                    'sizing' => [
                        'question'         => 'How many furnaces or air handlers?',
                        'help'             => 'One PlasmaGuard PRO installs into each system\'s ductwork, so most buildings need one per furnace or air handler.',
                        'units_per_system' => 1,
                        'max_systems'      => 20,
                        'coverage_note'    => 'Each generator covers up to 5 tons of cooling, 2,000 CFM, or 2,000–4,000 sq ft. A single system larger than that may need more than one.',
                        'confirm_note'     => 'PlasmaGuard confirms final sizing against your actual system before anything ships.',
                    ],

                    // Shown on the enquiry form. Kept per-product because a
                    // ductless unit does not need a tonnage question.
                    'qualifiers' => [
                        'property_type' => [
                            'label'   => 'Property type',
                            'options' => ['Commercial', 'Healthcare', 'Hospitality', 'Education', 'Residential', 'Marine', 'Other'],
                        ],
                        'system_size' => [
                            'label'   => 'Approximate HVAC size / square footage',
                            'options' => ['Under 2,000 sq ft', '2,000–4,000 sq ft', '4,000–10,000 sq ft', 'Over 10,000 sq ft', 'Not sure'],
                        ],
                    ],
                ],

            ],
        ],

    ],

];
