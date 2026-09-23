<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Opportunities\OpportunityTracker;
use App\Services\Presentations\ConversionTracker;
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
            // Back to the page that sent them to sign in, if there was one — a
            // Your Rooms link to one guest's conversation is useless if it
            // lands on the dashboard. Otherwise admins get the admin panel,
            // and a product partner gets their own section: the member
            // dashboard would only bounce them (KeepProductPartnersInPortal)
            // and flash a member-area page on the way.
            return redirect()->intended($this->homeFor(Auth::user()));
        }

        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    /**
     * Where an account belongs when nothing more specific was asked for.
     *
     * The account's own choice where it has made one, and the sensible default
     * for its kind where it has not — an admin who lives in the member area and
     * a vendor's sales person who lives in the back office should not each pay
     * a redirect every morning. See User::landingRoute().
     */
    private function homeFor(\App\Models\User $user): string
    {
        return $user->landingRoute();
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

    public function showReferral(Request $request, string $code)
    {
        // Arrived from a presentation's call to action. Held in the session so
        // the signup can be traced back to the guest who watched, whatever
        // address they end up using.
        app(ConversionTracker::class)->remember($request->query(ConversionTracker::PARAM));

        // Which marketing site and which business line sent them. The form
        // below is shared by every front door, so this is the only place the
        // difference can be captured — and it has to be captured before the
        // account exists.
        $tracker = app(OpportunityTracker::class);
        $tracker->remember(
            $request->query(OpportunityTracker::PARAM),
            $request->query(OpportunityTracker::SITE_PARAM),
        );

        return view('public.auth.referral', [
            'sponsor'     => $this->sponsorFor($code),
            'opportunity' => $tracker->current(),
        ]);
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

        // If they came from a presentation, tie the account to the guest who
        // watched it.
        app(ConversionTracker::class)->attribute($user);

        // And which front door they came through. This sets their primary
        // business line, which afterSignup() reads a moment later to decide
        // whether to ask for a card at all.
        app(OpportunityTracker::class)->attribute($user);

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
        // A business line that is not the membership has nothing to charge for,
        // so there is no card to capture and the billing screen would be a dead
        // end asking them to buy something they did not come for. The
        // subscription gate agrees — see RequireActiveSubscription.
        if ($user->hasActiveMembership() || ! $user->requiresMembership()) {
            return route('member.dashboard');
        }

        return route('member.billing.start');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }
}
