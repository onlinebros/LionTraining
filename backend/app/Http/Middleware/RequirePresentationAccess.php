<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the member side of presentations closed until it is released.
 *
 * Admins always pass, so the whole flow — including everything a member would
 * see — can be rehearsed before members get it. Flip
 * `PRESENTATIONS_OPEN_TO_MEMBERS=true` to open it.
 */
class RequirePresentationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('presentations.open_to_members') || $user?->isAdmin()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Not available yet.'], 403);
        }

        abort(403, 'Presentations are not open yet.');
    }
}
