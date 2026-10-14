<?php

namespace App\Console\Commands;

use App\Http\Middleware\RequireOwnerStatusToken;
use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issues the token the owner's assistant uses to read /api/owner-status/*.
 * The plain token is printed once, on its own OWNER_STATUS_TOKEN= line, and
 * only its SHA-256 is kept. Running it again replaces the old token, which
 * stops working at once; --revoke closes the API.
 */
class OwnerStatusToken extends Command
{
    protected $signature = 'owner-status:token {--revoke : Remove the token, closing the API}';

    protected $description = 'Issue (or revoke) the token the owner\'s assistant uses to read the owner-status API';

    public function handle(): int
    {
        if ($this->option('revoke')) {
            SiteSetting::set(RequireOwnerStatusToken::SETTING, '');
            $this->info('Token revoked. The owner-status API is closed.');

            return self::SUCCESS;
        }

        $token = Str::random(64);
        SiteSetting::set(RequireOwnerStatusToken::SETTING, hash('sha256', $token));

        $this->line('OWNER_STATUS_TOKEN='.$token);
        $this->info('Read-only owner-status token issued (shown once; any previous token has stopped working). Send it as a Bearer token to '.rtrim((string) config('app.url'), '/').'/api/owner-status/summary.');

        return self::SUCCESS;
    }
}
