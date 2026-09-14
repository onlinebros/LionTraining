<?php

namespace App\Http\Middleware;

use App\Support\Registration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes general registration while the platform is invitation only.
 *
 * Applied to the whole web and api groups rather than wrapped around the two
 * signup routes, so a URL typed by hand, a stale bookmark and a form posted by
 * a script are all closed the same way — and adding a route to
 * config('registration.closed_routes') is enough to shut it.
 *
 * The invitation flow (/join/{code}) is untouched: it carries the sponsor, and
 * it is the way in.
 */
class RequireInvitation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Registration::routeClosed($request->route()?->getName())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => Registration::notice()], 403);
        }

        // A GET is somebody following a link, so it gets a real page at 200 —
        // this is a deliberate product state, not an error, and answering 4xx
        // here would fill the error log for the whole invitation-only period.
        //
        // A POST is an attempt to actually create the account, so it is
        // refused with 403. Rendering the same friendly page at 200 for a
        // rejected write would tell a script it had succeeded.
        return response()->view(
            'public.invitation-required',
            ['notice' => Registration::notice()],
            $request->isMethod('GET') ? 200 : 403,
        );
    }
}
