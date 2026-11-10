<?php

return [
    /*
     * The address people are given, without the slug: https://q3.life →
     * https://q3.life/live-zoom. Only used to show and copy the link in the
     * admin; the redirect itself answers on any host that reaches the app.
     * On q3.life production that takes one nginx rule sending /live-* to
     * app.q3.life (see memory-bank/deployment.md).
     */
    'public_base_url' => rtrim(env('LIVE_LINKS_BASE_URL', 'https://q3.life'), '/'),

    // Every live link starts with this, which is what lets one nginx rule
    // route them all without touching it per link.
    'prefix' => 'live-',
];
