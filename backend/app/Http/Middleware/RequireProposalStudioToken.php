<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Proposal Studio relay API is called only by dev's worker, with the
 * token made by proposal-studio:token. Only its SHA-256 is stored. With no
 * token issued, or the studio switched off, the API does not exist.
 */
class RequireProposalStudioToken
{
    public const SETTING = 'proposal_studio_api_token_hash';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) SiteSetting::get(self::SETTING, '');
        $given = (string) $request->bearerToken();

        abort_if(! config('proposal-studio.enabled') || $expected === '' || $given === '' || ! hash_equals($expected, hash('sha256', $given)), 404);

        return $next($request);
    }
}
