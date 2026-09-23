<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which sections of the application a product partner may reach.
 *
 * Two different rules, for two different reasons:
 *
 *   admin.*   Never. They are an outside company's employee, not staff, and
 *             no amount of business line changes that.
 *
 *   member.*  Only once somebody has put them on a business line. A vendor's
 *             sales people sell for us and want the back office; their finance
 *             people do not, and should not be handed a CRM and a downline.
 *
 * The member rule is not about tidiness. Without a business line an account
 * reads as the training line, which requires a card — so letting an unlinked
 * product partner into the member area sends a vendor to a card capture screen
 * for a $49.99 membership nobody sold them. That is worse than a 403, because
 * it looks like we are trying to bill them.
 *
 * Attached to the whole web group rather than per route group, so a section
 * added later is covered the day it exists. Matched by route-name prefix, the
 * same mechanism PrelaunchGuard uses.
 */
class ProductPartnerSectionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isProductPartner()) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if ($name === null) {
            return $next($request);
        }

        // Staff-only, always.
        if (str_starts_with($name, 'admin.')) {
            return $this->refuse($request);
        }

        // The selling side, once they have been put on a line.
        if (str_starts_with($name, 'member.') && ! $user->canUseMemberArea()) {
            return $this->refuse($request);
        }

        return $next($request);
    }

    private function refuse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Not available on this account.'], 403);
        }

        return redirect()->route('product-partner.dashboard');
    }
}
