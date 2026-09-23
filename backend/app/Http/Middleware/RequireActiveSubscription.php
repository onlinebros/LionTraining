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

        /*
        | Business lines that are not the membership.
        |
        | A partner who came in through the PlasmaGuard site joined to sell air
        | purification systems, not to buy a $49.99 membership, so there is
        | nothing to capture a card for and nothing for this gate to protect.
        | They reach the back office directly.
        |
        | This is not an access grant. What they can see is decided separately
        | by the feature list on their business line — see
        | EnsureOpportunityFeature — and the training program, which IS the
        | membership's product, is not on it. The moment they add the
        | membership, requiresMembership() turns true and this gate applies to
        | them exactly as to everybody else.
        */
        if (! $user->requiresMembership()) {
            return $next($request);
        }

        // Only when config/prelaunch.php switches bypass_membership back on. It
        // ships off: pre-launch partners put a card on file like everyone else,
        // and their trial is parked until launch.
        if (Prelaunch::bypassesMembership()) {
            return $next($request);
        }

        $message = 'Add a payment method to start your Training Program. You will not be charged today.';

        if ($request->expectsJson()) {
            return response()->json([
                'message'  => $message,
                'redirect' => route('member.billing.start'),
            ], 402);
        }

        // `status` is the flash key the member layout renders.
        return redirect()->route('member.billing.start')->with('status', $message);
    }
}
