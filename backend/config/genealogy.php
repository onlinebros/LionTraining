<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Placement structure
    |--------------------------------------------------------------------------
    |
    | Quantum Life runs a unilevel: every partner is placed directly beneath the
    | person who enrolled them, so the enrollment tree and the placement tree
    | are the same shape and there is no spillover.
    |
    | The schema (users.placement_parent_id / placement_path, kept separate from
    | sponsor_id / enrollment_path) supports binary and matrix without a
    | migration, which is why this is a config key rather than an assumption
    | baked into the queries. Adding a structure means adding a strategy class
    | below and backfilling placement_path — no column changes.
    |
    | See docs/quantum-life-solutions/00-decisions.md § D1 in the reference repo.
    |
    */

    'structure' => env('QL_GENEALOGY_STRUCTURE', 'unilevel'),

    'strategies' => [
        'unilevel' => \App\Services\Genealogy\UnilevelPlacement::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tree display limits
    |--------------------------------------------------------------------------
    |
    | How deep the member-facing tree renders in one request. The genealogy
    | itself is unbounded; this only caps what one page draws, because a
    | partner with a large team can otherwise pull thousands of rows into a
    | single view.
    |
    */

    'tree_depth' => (int) env('QL_TREE_DEPTH', 5),

    /*
    |--------------------------------------------------------------------------
    | Root node
    |--------------------------------------------------------------------------
    |
    | Partners registering without a referral link have no sponsor and become
    | roots of their own tree. Set this to a user id to instead place every
    | unreferred signup under a house account.
    |
    */

    'default_sponsor_id' => env('QL_DEFAULT_SPONSOR_ID'),

];
