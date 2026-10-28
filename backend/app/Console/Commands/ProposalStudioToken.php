<?php

namespace App\Console\Commands;

use App\Http\Middleware\RequireProposalStudioToken;
use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issues the token dev's worker uses to take this server's Proposal Studio
 * jobs. Run it on the live site, then on dev run the proposal-studio:connect
 * line it prints. Running it again replaces the old token; --revoke closes
 * the API.
 */
class ProposalStudioToken extends Command
{
    protected $signature = 'proposal-studio:token {--revoke : Remove the token, closing the API}';

    protected $description = 'Issue (or revoke) the token dev uses to answer this server\'s Proposal Studio jobs';

    public function handle(): int
    {
        if ($this->option('revoke')) {
            SiteSetting::set(RequireProposalStudioToken::SETTING, '');
            $this->info('Token revoked. Dev can no longer take the Proposal Studio jobs here.');

            return self::SUCCESS;
        }

        $token = Str::random(64);
        SiteSetting::set(RequireProposalStudioToken::SETTING, hash('sha256', $token));

        $this->info('New token issued. Any previous token has stopped working. Run this on dev:');
        $this->newLine();
        $this->line('php artisan proposal-studio:connect '.rtrim((string) config('app.url'), '/').' '.$token);

        return self::SUCCESS;
    }
}
