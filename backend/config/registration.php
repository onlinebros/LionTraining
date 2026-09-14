<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation-only registration
    |--------------------------------------------------------------------------
    |
    | Quantum Life grows by sponsorship: a partner enrols the people they
    | invite, and everyone sits somewhere in the structure underneath the person
    | who brought them in. Open registration breaks that, because it produces
    | accounts with no sponsor that become roots of their own tree and belong to
    | nobody.
    |
    | While this is on, the only way in is an invitation link — /join/{code} —
    | which carries the sponsor. The general /register form and its API
    | equivalent are closed.
    |
    | Note the default is ON, which is the opposite of config/prelaunch.php.
    | That guard defaults off so a fresh checkout exercises the whole
    | application; this one is an access control, and an access control that
    | defaults open is one that is open on the environment somebody forgot to
    | configure. Opening the door is the decision that should require a
    | deliberate act.
    |
    */

    'invite_only' => (bool) env('QL_INVITE_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Guarded routes
    |--------------------------------------------------------------------------
    |
    | Exact route names the invitation guard closes. The middleware is attached
    | to the whole web and api groups and matches against this list, rather than
    | being wrapped around the two routes by hand, so a second signup route
    | added later is closed the moment it is named here — and reviewers have one
    | list to check instead of grepping for middleware.
    |
    | Matching is exact, never by prefix: 'join' as a prefix would close the
    | invitation flow itself the day someone adds 'join.confirm'.
    |
    | Deliberately NOT listed, and the reason why:
    |
    |   join, join.post          the invitation flow — this is the way in
    |   api.auth.sponsor-register  the API equivalent, also sponsor-carrying
    |   admin.users.store        staff creating accounts, already behind auth
    |
    */

    'closed_routes' => [
        'register',
        'register.post',
        'api.auth.register',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notice
    |--------------------------------------------------------------------------
    |
    | Shown on the invitation-required page. Wording matters here: this reader
    | tried to sign up and could not, and the useful thing to tell them is how
    | to get in, not that they were refused.
    |
    */

    'notice' => 'Quantum Life is invitation only. You need a referral link from an existing partner to create an account.',

];
