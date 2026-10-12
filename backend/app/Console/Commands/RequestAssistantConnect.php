<?php

namespace App\Console\Commands;

use App\Services\RequestAssistant\Upstream;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Points dev at the server that keeps the change requests (the live site):
 * the relay answers its waiting turns and Admin → Review Requests decides
 * them. The token comes from request-assistant:token on that server and is
 * stored encrypted with this server's APP_KEY.
 */
class RequestAssistantConnect extends Command
{
    protected $signature = 'request-assistant:connect {url? : e.g. https://app.q3.life} {token?} {--disconnect}';

    protected $description = 'Connect dev\'s Request Assistant relay and review screens to the server that keeps the requests';

    public function handle(Upstream $upstream): int
    {
        if ($this->option('disconnect')) {
            Upstream::disconnect();
            $this->info('Disconnected.');

            return self::SUCCESS;
        }

        $url = (string) $this->argument('url');
        $token = (string) ($this->argument('token') ?: $this->secret('Token from request-assistant:token'));
        if (! preg_match('#^https?://#', $url) || $token === '') {
            $this->error('Give the URL (https://…) and the token.');

            return self::FAILURE;
        }

        Upstream::connect($url, $token);

        try {
            $counts = $upstream->call('get', 'review/requests', ['status' => 'all'])['counts'] ?? [];
        } catch (HttpException) {
            Upstream::disconnect();
            $this->error('The server refused the token (404). Nothing was saved.');

            return self::FAILURE;
        } catch (RuntimeException $e) {
            Upstream::disconnect();
            $this->error($e->getMessage().' Nothing was saved.');

            return self::FAILURE;
        }

        $this->info("Connected to {$url}. It holds ".array_sum((array) $counts).' change request(s).');

        return self::SUCCESS;
    }
}
