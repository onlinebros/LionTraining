<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-launch guard
    |--------------------------------------------------------------------------
    |
    | While this is on, the member area narrows to the two things that have to
    | work before there is anything to sell: enrolling partners, and placing
    | them in the structure. Everything downstream of a real transaction is
    | closed — not deleted, not unbuilt, closed — and opens on launch day
    | without a deploy.
    |
    | Defaults to OFF and is switched on explicitly per environment. Production
    | sets QL_PRELAUNCH=true; dev and the test suite leave it off so they
    | exercise the whole application. Defaulting it on would quietly hide half
    | the member area from anyone who checks the repo out.
    |
    */

    'enabled' => (bool) env('QL_PRELAUNCH', false),

    /*
    |--------------------------------------------------------------------------
    | Closed sections
    |--------------------------------------------------------------------------
    |
    | Keyed by section name, valued by the route-name prefixes it owns. The
    | guard middleware and the sidebar both read this list, so a section can
    | never be hidden from the menu while its URLs stay reachable.
    |
    | Opening a section on launch day means removing its key here. Prefer
    | removing them one at a time over flipping `enabled` — a staged opening is
    | recoverable, a single flag flip is not.
    |
    */

    'closed' => [
        // Nothing has been earned yet, so these would render empty and look
        // broken rather than pending.
        'commissions' => ['member.commissions.'],

        // There are no customers to work during pre-launch, so a CRM full of
        // empty pipelines is a support burden rather than a feature.
        'crm' => ['member.crm.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exemptions
    |--------------------------------------------------------------------------
    |
    | Exact route names that stay reachable even though a `closed` prefix owns
    | them. Checked first, and the only way to punch a hole in a closed section.
    |
    | Matching is exact, never by prefix: 'member.commissions.payouts' as a
    | prefix would quietly open 'member.commissions.payouts.destroy' the day
    | someone adds it. A route added to a closed section later stays closed
    | until it is named here, which keeps the guard fail-closed.
    |
    */

    'open' => [
        // Payout account onboarding stays reachable while the payout screens
        // are shut. It is provider onboarding, and a partner who completes it
        // during pre-launch is one nobody has to chase for tax details in
        // January.
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership bypass
    |--------------------------------------------------------------------------
    |
    | Registration ends at card capture, and the subscription gate normally
    | holds partners at the door until the provider confirms a live membership.
    | During pre-launch nothing is billed yet, so that gate would lock every new
    | enrollee out of the tree they were just placed in.
    |
    | Deliberately tied to the guard rather than being its own switch: the day
    | the guard comes off is the day billing has to start mattering.
    |
    */

    'bypass_membership' => true,

    /*
    |--------------------------------------------------------------------------
    | Notices
    |--------------------------------------------------------------------------
    |
    | A closed section renders a real page rather than redirecting, so the
    | partner can see which section they reached and that it is coming, not
    | broken. Signed-out visitors get their own wording — the partner copy talks
    | about building a team, which means nothing to someone with no account.
    |
    */

    'notice' => 'This opens on launch day. Right now, focus on enrolling your team and watching your organisation grow.',

    'public_notice' => 'Quantum Life has not opened to the public yet. The person who sent you this link will be in touch when it does.',

    /*
    |--------------------------------------------------------------------------
    | Launch date
    |--------------------------------------------------------------------------
    |
    | Drives the countdown on the coming-soon page. Leave it unset if the date
    | might slip — a countdown that resets is worse than no countdown.
    |
    */

    'ends_at' => env('QL_PRELAUNCH_ENDS_AT'),

];
