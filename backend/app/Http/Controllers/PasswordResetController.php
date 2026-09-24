<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot password?" — email a one-time link, then set a new password.
 *
 * Laravel's password broker does the token work; the link goes out through
 * whatever MAIL_MAILER is (Resend on the droplet). Only activated accounts can
 * reset: a holding spot is claimed with its activation code, and a reset must
 * never become a way around that, nor revive a merged position.
 */
class PasswordResetController extends Controller
{
    /**
     * The broker filters on every credential that is not a password, so this
     * rides along with the email on both the request and the reset.
     */
    private const ACTIVE_ONLY = ['account_status' => User::ACCOUNT_ACTIVE];

    /** Shown whether or not the address has an account, so the form can't be used to find out. */
    private const SENT = 'If that email belongs to an account, a link to reset your password is on its way.';

    public function showRequest()
    {
        return view('public.auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $status = Password::sendResetLink($request->only('email') + self::ACTIVE_ONLY);
        } catch (\Throwable $e) {
            // The mail provider refused or was unreachable. Say so, rather
            // than tell them to watch an inbox nothing is coming to.
            report($e);

            return back()->withErrors(['email' => 'We could not send the email just now. Please try again in a few minutes.'])
                ->onlyInput('email');
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => 'A reset link was sent a moment ago. Please check your inbox, or wait a minute and try again.'])
                ->onlyInput('email');
        }

        return back()->with('status', self::SENT);
    }

    public function showReset(Request $request, string $token)
    {
        return view('public.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token') + self::ACTIVE_ONLY,
            function (User $user, string $password) {
                // The 'hashed' cast hashes it. A new remember token signs out
                // any "remember me" cookie issued under the old password.
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.'])
                ->onlyInput('email');
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. You can sign in now.');
    }
}
