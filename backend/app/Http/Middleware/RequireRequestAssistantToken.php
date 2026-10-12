<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host API is called only by the live site, with the token made by
 * `php artisan request-assistant:token`. Only its SHA-256 is stored. With no
 * token issued the API does not exist.
 */
class RequireRequestAssistantToken
{
    public const SETTING = 'request_assistant_api_token_hash';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) SiteSetting::get(self::SETTING, '');
        $given = (string) $request->bearerToken();

        abort_if($expected === '' || $given === '' || ! hash_equals($expected, hash('sha256', $given)), 404);

        return $next($request);
    }
}
