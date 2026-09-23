<?php

namespace App\Http\Middleware;

use App\Support\Opportunity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a member out of a section their business line does not include.
 *
 *     Route::middleware('opportunity:training')
 *
 * A B2B partner who joined to sell air purification systems has not bought the
 * training program and is not shown it — see config/opportunities.php.
 *
 * This answers 404 rather than 403, matching EnsureTrainingVisible: for a
 * member on the PlasmaGuard side the training library is not a locked door,
 * it is not part of what they joined, and a 403 only invites the question of
 * how to get through it. What they CAN get is offered where it belongs — on
 * the billing screen, as adding the membership — not as an error page.
 *
 * Admins pass everything: User::canSee() exempts them, because an
 * administrator previewing a section has no business line of their own.
 *
 * A feature name not in the catalogue is a programming error and throws. The
 * alternative is a typo that silently opens a route to everybody.
 */
class EnsureOpportunityFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! array_key_exists($feature, Opportunity::featureCatalogue())) {
            throw new \InvalidArgumentException(
                "Unknown opportunity feature '{$feature}'. Add it to config/opportunities.php."
            );
        }

        $user = $request->user();

        // Unauthenticated requests are somebody else's problem: 'auth' runs
        // first on every route this is used on, and deciding an anonymous
        // visitor's business line here would only duplicate that.
        if ($user === null || $user->canSee($feature)) {
            return $next($request);
        }

        abort(404);
    }
}
