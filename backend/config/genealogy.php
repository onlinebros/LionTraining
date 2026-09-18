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
    | Tree render ceiling
    |--------------------------------------------------------------------------
    |
    | The most rows one render of the team tree will pull into memory. Not a
    | display limit — tree_depth above does that — but a ceiling on what a
    | single request can cost.
    |
    | It exists because an organisation imported from a partner company can be
    | millions of positions: iHub's is 1.3 million under one account, and its
    | widest node has 6,035 direct children, so neither depth nor width bounds
    | the row count on its own. Below the ceiling nothing changes and every
    | count is exact; above it the render falls back to the levels that are
    | actually drawn and says so.
    |
    */

    'max_tree_rows' => (int) env('QL_MAX_TREE_ROWS', \App\Services\Genealogy\GenealogyService::MAX_TREE_ROWS),

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
