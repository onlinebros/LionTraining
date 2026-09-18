<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserAuthController extends Controller
{
    public function __construct(private EnrollmentService $enrollment) {}

    public function showLogin()
    {
        return view('public.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            // Admins who log in via the public login go to the admin panel
            if (Auth::user()->isAdmin()) {
                return redirect()->route('admin.dashboard');
            }
            return redirect()->intended(route('member.dashboard'));
        }

        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    public function showRegister()
    {
        return view('public.auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Account creation and enrollment share one transaction. Creating the
        // user outside it means a failed placement rolls back the enrollment
        // but leaves the account standing — able to log in, with no sponsor and
        // no paths, and carrying the default 'queued' status it was never
        // actually queued with. That row then looks placeable to
        // `network:place-queued`, which would silently root it in its own tree.
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            // No referral link, so no sponsor unless a house account is
            // configured — see config('genealogy.default_sponsor_id'). Either
            // way they are enrolled and placed now, so they never exist outside
            // the tree.
            return $this->enrollment->enroll($user, null);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->afterSignup($user));
    }

    public function showReferral(string $code)
    {
        return view('public.auth.referral', ['sponsor' => $this->sponsorFor($code)]);
    }

    /**
     * The partner behind a referral code.
     *
     * Activated accounts only. Two kinds of row now hold a position without
     * being a person who can sponsor anybody: an unclaimed partner-company spot,
     * and a position that has been merged into another account. Neither should
     * ever be found here — an unclaimed spot has no owner, and a merged one is
     * out of the structure, so placing a signup beneath either fails deep in
     * the genealogy with a 500 instead of a 404 on a link that is simply dead.
     */
    private function sponsorFor(string $code): User
    {
        return User::query()->activated()->where('referral_code', $code)->firstOrFail();
    }

    public function registerViaReferral(Request $request, string $code)
    {
        $sponsor = $this->sponsorFor($code);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Enrolled and placed in one transaction — position is real from the
        // moment they sign up, which is the whole commercial point of the
        // pre-launch phase. The account is created inside that same transaction
        // so a placement failure takes the account with it rather than leaving
        // a loggable-in row sitting outside the tree.
        $user = DB::transaction(function () use ($data, $sponsor) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            return $this->enrollment->enroll($user, $sponsor);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->afterSignup($user))->with('status', "You're now connected with {$sponsor->name} as your sponsor!");
    }

    /**
     * Where a new partner lands: card capture, unless their access does not
     * depend on a card. The subscription gate would send them there anyway, but
     * the extra redirect would drop the welcome message.
     */
    private function afterSignup(User $user): string
    {
        return $user->hasActiveMembership()
            ? route('member.dashboard')
            : route('member.billing.start');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }
}
