<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\ProductPartner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user)
    {
        $admin = $request->user();

        if ($reason = Impersonation::refusal($admin, $user)) {
            return back()->withErrors(['error' => $reason]);
        }

        // An admin-only "view as" has no meaning once the session is theirs.
        $request->session()->forget(ProductPartner::VIEW_AS);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $admin->id);

        // Everything done from here on is recorded against the member, so the
        // log is the only place that says who was really at the keyboard.
        Log::info('Impersonation started', [
            'admin_id' => $admin->id, 'admin_email' => $admin->email,
            'user_id'  => $user->id,  'user_email'  => $user->email,
            'ip'       => $request->ip(),
        ]);

        return redirect($user->landingRoute());
    }

    /**
     * Back to the admin's own account. Outside the admin middleware, because
     * the session making this request is the member's.
     */
    public function stop(Request $request)
    {
        $admin  = Impersonation::impersonator();
        $member = $request->user();

        if ($admin === null) {
            return redirect($member?->landingRoute() ?? route('login'));
        }

        // Demoted or deleted while they were away: end the session rather than
        // hand a back office to someone who no longer holds one.
        if (! $admin->isSuperAdmin() || ! $admin->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.auth.login');
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget(Impersonation::SESSION_KEY);

        Log::info('Impersonation ended', [
            'admin_id' => $admin->id,
            'user_id'  => $member?->id,
        ]);

        return $member
            ? redirect()->route('admin.users.show', $member)->with('success', 'Signed out of '.$member->name.'\'s account.')
            : redirect()->route('admin.users.index');
    }
}
