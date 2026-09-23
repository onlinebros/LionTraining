<?php

namespace App\Http\Controllers\ProductPartner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ProductPartner;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared ground for every screen in the vendor portal.
 *
 * Two jobs, and both exist so that no controller in this namespace reads them
 * off the request itself:
 *
 *   subject()  whose eyes this is being read through. Normally the signed-in
 *              account; for an admin using "View as", the partner they picked.
 *   vendor()   which company's customers are on the page, resolved against that
 *              subject's grants exactly once.
 *
 * A controller here that uses $request->user() for either is a bug: it would
 * scope the page to the admin, who can see every vendor, and quietly turn
 * "what PlasmaGuard sees" into "everything".
 */
abstract class PortalController extends Controller
{
    /**
     * The account whose access this page reflects.
     *
     * @see ProductPartner::viewedBy()
     */
    protected function subject(Request $request): User
    {
        return ProductPartner::viewedBy($request->user());
    }

    /**
     * The vendor this request is about, already checked against the grants.
     *
     * RequireProductPartner has established there is at least one, so a null
     * here would mean the grants changed mid-request — treated as gone rather
     * than guessed at.
     */
    protected function vendor(Request $request): string
    {
        $vendor = ProductPartner::resolveVendor($this->subject($request), $request->query('vendor'));

        if ($vendor === null) {
            throw new NotFoundHttpException('No product line is linked to this account.');
        }

        return $vendor;
    }

    /**
     * What the layout needs on every page: who this is and what else they can
     * switch to.
     *
     * @return array<string, mixed>
     */
    protected function shell(Request $request, string $vendor): array
    {
        $subject = $this->subject($request);
        $slugs   = ProductPartner::vendors($subject);

        return [
            'vendor'       => $vendor,
            'vendorName'   => Vendors::name($vendor),
            // Only rendered when there is more than one, so the common case —
            // one company, one product line — carries no chrome for a choice
            // that does not exist.
            'vendorSwitch' => count($slugs) > 1
                ? array_combine($slugs, array_map(fn ($s) => Vendors::name($s), $slugs))
                : [],

            // Null unless an admin is looking through somebody. The layout
            // turns this into the banner that says whose view this is — an
            // impersonated session that does not announce itself is how a
            // screenshot ends up in the wrong conversation.
            'viewingAs' => $subject->isNot($request->user()) ? $subject : null,
        ];
    }
}
