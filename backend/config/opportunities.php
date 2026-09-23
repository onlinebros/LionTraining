<?php

/*
|--------------------------------------------------------------------------
| Opportunity registry
|--------------------------------------------------------------------------
|
| An *opportunity* is a business line somebody can join us for. One back
| office, several front doors: q3.life sells the membership and the training
| program; the PlasmaGuard site sells air purification systems to businesses.
| The same tables, the same login, the same genealogy — but a person who came
| in to sell air purifiers should not be shown a training library they never
| bought, and should not be asked for a card to buy something they did not
| come for.
|
| So each member carries a primary opportunity (the door they came in by) and
| may pick up others later. Two things are decided from that set:
|
|   1. Whether the $49.99 membership — and therefore a card — is required at
|      all. `membership.required` below. A member whose opportunities are all
|      card-free walks straight into the back office.
|   2. What they see. `features` is the list of back-office sections an
|      opportunity opens, and a member sees the UNION of their opportunities'
|      features. Adding the training program to an account is therefore one
|      row, not a migration.
|
| Both are config rather than code because they are commercial decisions. What
| a B2B partner should see will be argued about, and that argument should end
| in an edit here, not a deploy of new middleware.
|
| See App\Support\Opportunity for the accessor and
| App\Http\Middleware\EnsureOpportunityFeature for the route-level gate.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default
    |--------------------------------------------------------------------------
    |
    | What a member with no recorded opportunity is treated as. Every account
    | that existed before this file did falls here, which is why the default
    | has to stay the one whose behaviour is unchanged: membership required,
    | everything visible. Nothing was backfilled and nothing needed to be.
    |
    */

    'default' => env('DEFAULT_OPPORTUNITY', 'q3-training'),

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Every gateable section of the member area, listed once so a typo in an
    | opportunity's feature list is caught at boot rather than silently hiding
    | a page. The keys are what routes and the sidebar ask for.
    |
    */

    'features' => [
        'dashboard'     => 'Dashboard',
        'team'          => 'My Team',
        'referrals'     => 'Referrals',
        'training'      => 'Training library',
        'product-sales' => 'Product Sales',
        'commissions'   => 'Commissions',
        'presentations' => 'Presentations',
        'rooms'         => 'Your Rooms',
        'video-flows'   => 'Video Flows',
        'prospects'     => 'Prospects',
        'crm'           => 'My CRM',
        'billing'       => 'Billing',
        'payouts'       => 'Get Paid',
        'support'       => 'Support',
    ],

    /*
    |--------------------------------------------------------------------------
    | Opportunities
    |--------------------------------------------------------------------------
    */

    'opportunities' => [

        'q3-training' => [
            'name'       => 'Training Program',
            'short_name' => 'Training',
            'tagline'    => 'The Q3 video training program. An optional add-on to a partner account.',

            // The public site this opportunity is sold from. Recorded on the
            // member so "which front door" is answerable without guessing from
            // a referrer header that half of browsers no longer send.
            'site'       => 'q3.life',

            /*
            | No `site_url` here on purpose. The company site's address already
            | lives in config/registration.php, which is what
            | Opportunity::partnerSiteUrl() falls back to — and two configs
            | holding the same URL is two configs that can disagree.
            */

            /*
            | The membership is the product on this line, so when it is being
            | sold the card is the first thing asked for. 'required' is what
            | RequireActiveSubscription has always enforced; naming it makes the
            | PlasmaGuard case a value rather than an exception.
            |
            | `enrollment_open` is the switch over the top of all of it, and it
            | ships CLOSED (owner, 2026-09-22): the training program is not
            | being sold yet, so nobody — new partner or old — is asked for a
            | card anywhere in the application. The Training Program section
            | exists and says it is opening soon, and partners can register
            | interest.
            |
            | Set MEMBERSHIP_ENROLLMENT_OPEN=true on the day it opens. That one
            | variable turns the section into a real offer, brings back card
            | capture for anyone who chooses to join, and re-arms the
            | subscription gate. Nothing else has to change, and nobody is
            | charged retroactively: a partner is only ever billed after they
            | choose an enrollment option themselves.
            */
            'membership' => [
                'required'        => true,
                'card'            => 'required',   // required | deferred
                'enrollment_open' => (bool) env('MEMBERSHIP_ENROLLMENT_OPEN', false),
            ],

            // Everything. This opportunity is the whole application.
            'features' => [
                'dashboard', 'team', 'referrals', 'training', 'product-sales',
                'commissions', 'presentations', 'rooms', 'video-flows',
                'prospects', 'crm', 'billing', 'payouts', 'support',
            ],
        ],

        'plasmaguard' => [
            'name'       => 'PlasmaGuard Products',
            'short_name' => 'PlasmaGuard',
            'tagline'    => 'Sell commercial air purification to businesses.',

            /*
            | One public site, product-first, so both lines are sold from the
            | same place and neither carries a `site_url` — the address lives
            | once in config/registration.php. A second line with its own domain
            | would set one here; see Opportunity::partnerSiteUrl().
            */
            'site'       => 'q3.life',

            /*
            | The B2B side. These partners came to sell somebody else's
            | hardware for a commission out of our revenue share; the $49.99
            | membership and the training program are not what they were
            | offered, so no card is taken and none is needed to work.
            |
            | 'deferred' rather than 'never': a partner who later wants the
            | training program adds the q3-training opportunity, and at that
            | point the ordinary card capture applies. The difference between
            | the two values is only when we ask, never whether we may.
            */
            'membership' => [
                'required' => false,
                'card'     => 'deferred',
            ],

            /*
            | No training (not bought), and none of the presentation or video
            | funnel tooling, which is built around the Q3 opportunity meeting.
            | Everything needed to sell a system, get paid for it and keep the
            | customer's record is here.
            |
            | If the B2B side turns out to want presentations, that is one word
            | in this array.
            */
            'features' => [
                'dashboard', 'team', 'referrals', 'product-sales',
                'commissions', 'prospects', 'crm', 'billing', 'payouts', 'support',
            ],

            /*
            | The vendor and product this opportunity is built around, so the
            | dashboard and the sidebar can point straight at the storefront a
            | partner is meant to be sharing rather than at a product list of
            | one. Both are keys into config/vendors.php.
            */
            'vendor'  => 'plasmaguard',
            'product' => 'pro-in-duct',
        ],

    ],

];
