<?php

namespace App\Console\Commands;

use App\Services\ProposalStudio\Upstream;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Points dev's Proposal Studio worker at the live site's queue too. The
 * token comes from proposal-studio:token there and is stored encrypted with
 * this server's APP_KEY.
 */
class ProposalStudioConnect extends Command
{
    protected $signature = 'proposal-studio:connect {url? : e.g. https://app.q3.life} {token?} {--disconnect}';

    protected $description = 'Connect dev\'s Proposal Studio worker to the live site\'s queue';

    public function handle(Upstream $upstream): int
    {
        if ($this->option('disconnect')) {
            Upstream::disconnect();
            $this->info('Disconnected.');

            return self::SUCCESS;
        }

        $url = (string) $this->argument('url');
        $token = (string) ($this->argument('token') ?: $this->secret('Token from proposal-studio:token'));
        if (! preg_match('#^https?://#', $url) || $token === '') {
            $this->error('Give the URL (https://…) and the token.');

            return self::FAILURE;
        }

        Upstream::connect($url, $token);

        try {
            // Checks the token by asking for nothing: a claim with no jobs waiting.
            $upstream->post('relay/ping');
        } catch (RuntimeException $e) {
            Upstream::disconnect();
            $this->error($e->getMessage().' Nothing was saved.');

            return self::FAILURE;
        }

        $this->info("Connected to {$url}. The worker now answers its jobs too.");

        return self::SUCCESS;
    }
}
