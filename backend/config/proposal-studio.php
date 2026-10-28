<?php

/*
|--------------------------------------------------------------------------
| Proposal Studio
|--------------------------------------------------------------------------
|
| Super admins build a picture of a prospect company with an AI assistant
| that knows the offering we are presenting (PlasmaGuard first), and get back
| a recommended plan and the emails that explain it. The client portal comes
| later. Plan and owner decisions: memory-bank/proposal-studio.md.
|
| The assistant runs on this server's Claude CLI (the owner's subscription),
| from proposal-studio:work, run as the user the CLI is logged in as. The
| live site runs no AI (owner, 2026-10-12, decision D1 2026-10-20), so the
| studio ships switched off and is turned on in dev's .env only.
|
*/

return [

    // Off unless set. A turn queued where no worker runs would wait forever.
    'enabled' => (bool) env('PROPOSAL_STUDIO_ENABLED', false),

    // The same CLI and login the Request Assistant uses.
    'claude_bin' => env('PROPOSAL_STUDIO_CLAUDE_BIN', env('REQUEST_ASSISTANT_CLAUDE_BIN', '/home/ai/.npm-global/bin/claude')),
    'claude_home' => env('PROPOSAL_STUDIO_CLAUDE_HOME', env('REQUEST_ASSISTANT_CLAUDE_HOME', '/home/ai')),

    // An empty directory for the CLI to start in. It has no tools, so it
    // never reads it, but it must not start inside the repository.
    'workdir' => env('PROPOSAL_STUDIO_WORKDIR', rtrim((string) env('REQUEST_ASSISTANT_CLAUDE_HOME', '/home/ai'), '/').'/.cache/q3-proposal-studio/empty'),

    'model' => env('PROPOSAL_STUDIO_MODEL', 'sonnet'),
    'timeout_seconds' => (int) env('PROPOSAL_STUDIO_TIMEOUT', 540),

    // Messages one person may send per hour, across all prospects.
    'hourly_limit' => (int) env('PROPOSAL_STUDIO_HOURLY_LIMIT', 40),

    /*
     | How much of the offering's documents goes into each prompt, in
     | characters (about four to a token). While the library fits, documents
     | are sent whole; past that, the passages that best match the
     | conversation are chosen (ProposalStudio\Knowledge).
     */
    'context_chars' => (int) env('PROPOSAL_STUDIO_CONTEXT_CHARS', 150000),

    /*
     | Vendor review (owner decision D5, 2026-10-20): the vendor reviews the
     | first few proposals published for each of their offerings, then
     | spot-checks the rest (they see every published proposal: D6).
     */
    'vendor_review_first' => (int) env('PROPOSAL_STUDIO_VENDOR_REVIEW_FIRST', 3),

    /*
     | The client portal (D3: invited emails with a sign-in link).
     */
    'portal' => [
        'link_days' => 14,
        'upload_max_kb' => 15360,
        'upload_max_files' => 6,
        'upload_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'docx', 'xlsx'],
        // Questions and submissions one invitee may send per hour.
        'hourly_limit' => 12,
    ],

    /*
     | The portal assistant answers prospects' questions on the spot (D1:
     | prospect-facing AI uses a Claude API key on the live site, never the
     | owner's subscription). Without a key, questions go to the admin, who
     | answers from the studio. Answers come only from approved claims, the
     | documents marked shareable, and the prospect's own proposal.
     */
    'portal_ai' => [
        'api_key' => env('PROPOSAL_STUDIO_ANTHROPIC_API_KEY'),
        'model' => env('PROPOSAL_STUDIO_PORTAL_MODEL', 'claude-opus-5-5'),
        'effort' => env('PROPOSAL_STUDIO_PORTAL_EFFORT', 'medium'),
        // Answers per portal per day, so a link passed around cannot run up a bill.
        'daily_limit' => (int) env('PROPOSAL_STUDIO_PORTAL_DAILY_LIMIT', 40),
        'context_chars' => 120000,
    ],

    /*
     | Business accounts' microsites (memory-bank/b2b-accounts.md): invited
     | people, colleagues they ask us to add, and the client's logo.
     */
    'microsite' => [
        // Sections one admin may send to the assistant per hour.
        'hourly_drafts' => (int) env('PROPOSAL_STUDIO_MICROSITE_HOURLY_DRAFTS', 30),
        // Colleagues one member may ask for per day.
        'daily_requests' => 5,
        // Comments one member may leave per hour.
        'hourly_comments' => 12,
        // No SVG: it can carry script.
        'logo_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'logo_max_kb' => 2048,
    ],

    // Uploads to the knowledge library.
    'upload_max_kb' => 30720,
    'upload_mimes' => ['pdf', 'txt', 'md', 'html', 'htm', 'docx', 'pptx', 'csv'],

];
