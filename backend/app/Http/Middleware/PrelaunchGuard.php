<?php

namespace App\Http\Middleware;

use App\Support\Prelaunch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the sections that are not part of the pre-launch phase.
 *
 * Applied to whole route groups rather than checked inside controllers, so a
 * URL typed by hand is closed exactly like a hidden menu item. Users carrying
 * the prelaunch_preview flag — and all admins — pass straight through.
 */
class PrelaunchGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $section = Prelaunch::sectionForRoute($request->route()?->getName());

        if ($section === null || ! Prelaunch::closed($section, $request->user())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => config('prelaunch.notice'),
                'section' => $section,
            ], 403);
        }

        // Closed sections can include public URLs, so a visitor with no account
        // can land here. The member page extends a layout that links at a
        // dashboard they cannot reach, and its copy is about building a team,
        // which means nothing to them — they get their own chrome and wording.
        if ($request->user() === null) {
            return response()->view('public.coming-soon', [
                'section' => $section,
                'title'   => Prelaunch::label($section),
                'notice'  => config('prelaunch.public_notice'),
            ]);
        }

        // A real page rather than a redirect: the partner should see which
        // section they reached and that it is coming, not that it is broken.
        //
        // Answered 200, not 503: this is a deliberate product state, not an
        // outage, and a 5xx here would light up uptime checks and the error log
        // for the entire pre-launch period.
        return response()->view('member.coming-soon', [
            'section' => $section,
            'title'   => Prelaunch::label($section),
            'notice'  => config('prelaunch.notice'),
            'endsAt'  => Prelaunch::endsAt(),
        ]);
    }
}
