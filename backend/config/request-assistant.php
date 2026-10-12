<?php

/*
|--------------------------------------------------------------------------
| Request Assistant
|--------------------------------------------------------------------------
|
| Super admins describe a change they want; a read-only Claude looks at the
| code with them and drafts a plan; it is decided on dev whether it is built.
| Nothing the assistant says changes the application.
|
| Requests are kept on the live site. Dev collects the turns waiting there,
| answers them on its own Claude CLI (the owner's subscription), and is where
| they are reviewed. The connection is made by artisan commands, not .env:
| request-assistant:token on live, request-assistant:connect on dev.
| Full design and the guardrails in memory-bank/request-assistant.md.
|
*/

return [

    // The Claude Code CLI, logged in as the account whose quota pays for it.
    // It runs from the relay (request-assistant:relay) on dev, never from PHP-FPM.
    'claude_bin' => env('REQUEST_ASSISTANT_CLAUDE_BIN', '/home/ai/.npm-global/bin/claude'),

    // HOME for the CLI process: where its login lives. Everything else from the
    // worker's environment (database password, Stripe keys…) is withheld.
    'claude_home' => env('REQUEST_ASSISTANT_CLAUDE_HOME', '/home/ai'),

    'model' => env('REQUEST_ASSISTANT_MODEL', 'sonnet'),

    // Tool calls a single reply may make, and how long it may take.
    'max_turns' => (int) env('REQUEST_ASSISTANT_MAX_TURNS', 40),
    'timeout_seconds' => (int) env('REQUEST_ASSISTANT_TIMEOUT', 600),

    // Messages one person may send per hour, across all their requests.
    'hourly_limit' => (int) env('REQUEST_ASSISTANT_HOURLY_LIMIT', 30),

    /*
     | The copy of the project the assistant reads. Built from the committed
     | tree (git archive HEAD), so .env, storage/, vendor/ and anything
     | untracked are never in it. The repository it is built from is the one
     | this application runs out of.
     */
    'repository' => env('REQUEST_ASSISTANT_REPOSITORY', base_path('..')),
    // Outside the repository on purpose: inside it, the repo's .gitignore
    // (storage/ is ignored) makes the CLI's search tool skip every file.
    'workspace' => env('REQUEST_ASSISTANT_WORKSPACE', rtrim((string) env('REQUEST_ASSISTANT_CLAUDE_HOME', '/home/ai'), '/').'/.cache/q3-request-assistant/workspace'),

    // Tracked paths left out of the copy. Directory entries end in '/'.
    'exclude' => [
        'scripts/',                     // Kartra scrapers — a committed Kartra password
        'deploy/',                      // server and droplet configuration
        'memory-bank/deployment.md',    // hosts, keys, how production is reached
        '.github/',
        'starter-kit/',
        'template/',
    ],

    // Media and archives: no use to the assistant and most of the copy's size.
    'exclude_extensions' => [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg',
        'mp4', 'mov', 'webm', 'mp3', 'wav',
        'pdf', 'zip', 'gz', 'tgz', 'rar', '7z',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'psd', 'ai', 'sketch', 'fig',
    ],

];
