<?php

namespace App\Services\RequestAssistant;

use App\Models\SiteSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Dev's connection to the server that keeps the change requests (the live
 * site). Dev always calls out; the live site never calls dev. Set with
 * `php artisan request-assistant:connect`; the token is stored encrypted.
 */
class Upstream
{
    public const SETTING = 'request_assistant_upstream';

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

    public function url(): string
    {
        return (string) (self::config()['url'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, array $data = []): array
    {
        $config = self::config() ?? throw new RuntimeException('Dev is not connected to the live site. Run php artisan request-assistant:connect.');

        try {
            /** @var Response $response */
            $response = $this->client($config)->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new RuntimeException("The live site is not reachable: {$e->getMessage()}");
        }

        if ($response->status() === 404) {
            abort(404);
        }
        if (! $response->successful()) {
            throw new RuntimeException("The live site answered {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return (array) $response->json();
    }

    /** @param array{url: string, token: string} $config */
    private function client(array $config): PendingRequest
    {
        return Http::baseUrl($config['url'].'/api/request-assistant')
            ->withToken($config['token'])
            ->acceptJson()
            ->timeout(30);
    }
}
