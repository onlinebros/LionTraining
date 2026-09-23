<?php

namespace App\Http\Middleware;

use App\Support\ProductPartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to the product partner portal.
 *
 * Two separate conditions, refused differently on purpose:
 *
 *   Wrong kind of account → 403. A member or a stranger has no business here
 *   and should be told so plainly.
 *
 *   Right role, no grant → a page explaining it. This is the state an account
 *   is in between "the role was assigned" and "somebody linked them to a
 *   product", which is minutes in practice and confusing if it looks like a
 *   permissions error.
 *
 * Admins pass. They see all of this on the admin side already, and being able
 * to open exactly what the vendor opens is how a question about it gets
 * answered.
 */
class RequireProductPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! $user->isProductPartner() && ! $user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            abort(403, 'Access denied. Product Partner role required.');
        }

        /*
         * Checked against whoever is being looked through, not the signed-in
         * account. An admin viewing as a partner who has nothing linked should
         * meet the holding page that partner meets — that is the whole point of
         * "see what they would see", and it is the state most worth being able
         * to reproduce.
         */
        $subject = ProductPartner::viewedBy($user);

        if (! ProductPartner::hasAnyAccess($subject)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No products are linked to this account yet.'], 403);
            }

            // Not an abort: there is nothing wrong with the account, it is
            // waiting on somebody at our end.
            //
            // The holding page stands alone rather than using the portal
            // layout — there is no sidebar to show when nothing is linked — so
            // an admin who reached it through "View as" is handed the way back
            // explicitly. Without it they would be stuck on a page with no
            // navigation at all.
            return response()->view('product-partner.no-access', [
                'viewingAs' => $subject->isNot($user) ? $subject : null,
            ], 200);
        }

        return $next($request);
    }
}
