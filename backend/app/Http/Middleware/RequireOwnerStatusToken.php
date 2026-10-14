<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner-status API is read by the owner's assistant, with the token made
 * by `php artisan owner-status:token`. Only its SHA-256 is stored. With no
 * token issued the API does not exist.
 */
class RequireOwnerStatusToken
{
    public const SETTING = 'owner_status_api_token_hash';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) SiteSetting::get(self::SETTING, '');
        $given = (string) $request->bearerToken();

        abort_if($expected === '' || $given === '' || ! hash_equals($expected, hash('sha256', $given)), 404);

        return $next($request);
    }
}
