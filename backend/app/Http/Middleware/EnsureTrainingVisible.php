<?php

namespace App\Http\Middleware;

use App\Support\TrainingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the training library completely while it is admin-only.
 *
 * This answers with 404, not 403. A 403 confirms there is something there and
 * invites the question of when it opens; the brief was that none of it is
 * viewable on live except to admins, and the honest reading of that is that the
 * library should not be discoverable at all.
 *
 * It sits OUTSIDE 'subscribed' on the media routes, because an admin previewing
 * the library has no subscription of their own and must not be bounced to a
 * billing screen. See routes/web.php.
 */
class EnsureTrainingVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        if (TrainingAccess::visibleTo($request->user())) {
            return $next($request);
        }

        abort(404);
    }
}
