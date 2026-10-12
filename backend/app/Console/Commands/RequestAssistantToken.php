<?php

namespace App\Console\Commands;

use App\Http\Middleware\RequireRequestAssistantToken;
use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issues the token dev uses to reach the change requests kept on this server.
 * Run it on the live site, then on dev run the request-assistant:connect line
 * it prints. Running it again replaces the old token, which stops working at
 * once; --revoke closes the API.
 */
class RequestAssistantToken extends Command
{
    protected $signature = 'request-assistant:token {--revoke : Remove the token, closing the API}';

    protected $description = 'Issue (or revoke) the token dev uses to reach this server\'s change requests';

    public function handle(): int
    {
        if ($this->option('revoke')) {
            SiteSetting::set(RequireRequestAssistantToken::SETTING, '');
            $this->info('Token revoked. Dev can no longer reach the change requests here.');

            return self::SUCCESS;
        }

        $token = Str::random(64);
        SiteSetting::set(RequireRequestAssistantToken::SETTING, hash('sha256', $token));

        $this->info('New token issued. Any previous token has stopped working. Run this on dev:');
        $this->newLine();
        $this->line('php artisan request-assistant:connect '.rtrim((string) config('app.url'), '/').' '.$token);

        return self::SUCCESS;
    }
}
