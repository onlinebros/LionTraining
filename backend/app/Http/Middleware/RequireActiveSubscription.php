<?php

namespace App\Http\Middleware;

use App\Support\Prelaunch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds partners out of gated areas until their membership is live.
 *
 * A trialing subscription counts as live — the whole point of the trial is full
 * access before the first charge. What this blocks is the partner who registered
 * but never finished card capture, and the one whose subscription the provider
 * eventually gave up on.
 *
 * Admins and billing-exempt accounts pass: their access does not come from a
 * card. Both are checked inside hasActiveMembership(), before any subscription
 * lookup.
 *
 * IMPORTANT: every route in the billing group is OUTSIDE this middleware. This
 * gate redirects to the billing screen, so gating the billing screen too puts
 * the user in a redirect loop they cannot escape. That mistake is the single
 * most common way this pattern ships broken — see routes/web.php, where the
 * billing group sits above the guarded group with the same warning.
 */
class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasActiveMembership()) {
            return $next($request);
        }

        // During pre-launch nothing is billed yet, so this gate would lock every
        // new enrollee out of the tree they were just placed in. PrelaunchGuard
        // still closes everything that costs or pays money, so passing them
        // through here exposes nothing.
        if (Prelaunch::bypassesMembership()) {
            return $next($request);
        }

        $message = 'Add a payment method to activate your membership. You will not be charged today.';

        if ($request->expectsJson()) {
            return response()->json([
                'message'  => $message,
                'redirect' => route('member.billing.start'),
            ], 402);
        }

        return redirect()->route('member.billing.start')->with('info', $message);
    }
}
