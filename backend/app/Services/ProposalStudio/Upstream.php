<?php

namespace App\Services\ProposalStudio;

use App\Models\SiteSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Dev's connection to the live site's Proposal Studio queue. Dev always calls
 * out; the live site never calls dev. Set with proposal-studio:connect; the
 * token is stored encrypted. Separate from the Request Assistant's token,
 * because this one reaches prospect data.
 */
class Upstream
{
    public const SETTING = 'proposal_studio_upstream';

    public static function isConnected(): bool
    {
        return self::config() !== null;
    }

    /** @return ?array{url: string, token: string} */
    public static function config(): ?array
    {
        $stored = (string) SiteSetting::get(self::SETTING, '');
        if ($stored === '') {
            return null;
        }

        try {
            $config = json_decode(Crypt::decryptString($stored), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($config) && isset($config['url'], $config['token']) ? $config : null;
    }

    public static function connect(string $url, string $token): void
    {
        SiteSetting::set(self::SETTING, Crypt::encryptString(json_encode(['url' => rtrim($url, '/'), 'token' => $token])));
    }

    public static function disconnect(): void
    {
        SiteSetting::set(self::SETTING, '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        $config = self::config() ?? throw new RuntimeException('Not connected. Run php artisan proposal-studio:connect.');

        try {
            $response = Http::baseUrl($config['url'].'/api/proposal-studio')
                ->withToken($config['token'])
                ->acceptJson()
                ->timeout(60)
                ->post($path, $data);
        } catch (ConnectionException $e) {
            throw new RuntimeException("The live site is not reachable: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RuntimeException("The live site answered {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return (array) $response->json();
    }
}
