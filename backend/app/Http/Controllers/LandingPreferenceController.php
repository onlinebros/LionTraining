<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where an account lands after signing in, when it holds more than one section.
 *
 * An admin who spends their day in the member area, and a vendor's sales person
 * who lives in the back office rather than the statement, should not each pay a
 * redirect every morning for a default somebody else chose.
 *
 * Deliberately not a page. It is one choice, and it belongs in the profile menu
 * of whichever shell they are already in rather than behind a link to a
 * settings screen holding a single field.
 */
class LandingPreferenceController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        /*
         * Validated against what THIS account can actually reach, not against
         * the three keys in the abstract. Otherwise a member could store
         * 'admin' — harmless today, because landingRoute() re-checks before
         * honouring it, but a stored preference nobody can satisfy is a trap
         * for the next person who trusts the column.
         */
        $data = $request->validate([
            'landing' => ['required', Rule::in(array_keys($user->landingOptions()))],
        ]);

        $user->forceFill(['landing_preference' => $data['landing']])->save();

        return back()->with('status',
            'You will land on '.strtolower($user->landingOptions()[$data['landing']]).' when you sign in.');
    }
}
