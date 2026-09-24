<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CommissionClawbackController;
use App\Http\Controllers\Admin\CommissionLedgerController;
use App\Http\Controllers\Admin\CommissionPayoutController;
use App\Http\Controllers\Admin\CommissionPlanController;
use App\Http\Controllers\Admin\CrmActivityController;
use App\Http\Controllers\Admin\CrmContactController;
use App\Http\Controllers\Admin\CrmDashboardController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ErrorLogController;
use App\Http\Controllers\Admin\KartraImportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\HoldingSpotController;
use App\Http\Controllers\Admin\PartnerActivationController;
use App\Http\Controllers\Admin\PartnerCompanyController;
use App\Http\Controllers\Admin\PartnerWebhookController;
use App\Http\Controllers\Admin\SpotImportController;
use App\Http\Controllers\Admin\SponsorController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\TrainingCategoryController;
use App\Http\Controllers\Admin\TrainingContentBlockController;
use App\Http\Controllers\Admin\TrainingLessonController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VideoAssetController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\ConnectAccountController;
use App\Http\Controllers\MemberBillingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberCrmController;
use App\Http\Controllers\MemberPayoutController;
use App\Http\Controllers\MemberSupportController;
use App\Http\Controllers\PartnerClaimController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\UserAuthController;
use App\Http\Controllers\VendorStorefrontController;
use App\Http\Controllers\MemberVendorLeadController;
use App\Http\Controllers\Admin\VendorLeadController;
use App\Http\Controllers\Admin\CtaItemController as AdminCtaItemController;
use App\Http\Controllers\Admin\PresentationController as AdminPresentationController;
use App\Http\Controllers\Admin\PresentationFunnelController as AdminFunnelController;
use App\Http\Controllers\Admin\PresentationSeriesController as AdminPresentationSeriesController;
use App\Http\Controllers\Admin\ScreenRecordingController;
use App\Http\Controllers\FunnelWatchController;
use App\Http\Controllers\Member\FunnelController as MemberFunnelController;
use App\Http\Controllers\Member\PresentationController as MemberPresentationController;
use App\Http\Controllers\Member\TrainingMediaController;
use App\Http\Controllers\Member\TrainingProgramController;
use App\Http\Controllers\LandingPreferenceController;
use App\Http\Controllers\PresentationWatchController;
use App\Http\Controllers\ProductPartner\DashboardController as PartnerDashboardController;
use App\Http\Controllers\ProductPartner\ProspectController as PartnerProspectController;
use App\Http\Controllers\ProductPartner\SalesController as PartnerSalesController;
use App\Http\Controllers\ProductPartner\StatementController as PartnerStatementController;
use App\Http\Controllers\Admin\ProductPartnerController;
use App\Http\Controllers\ScreenRecordingPlaybackController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', fn() => view('public.home'))->name('home');

// Referral sign-up (public, no auth required, before guest middleware)
Route::get('/join/{code}', [UserAuthController::class, 'showReferral'])->name('join');
Route::post('/join/{code}', [UserAuthController::class, 'registerViaReferral'])->name('join.post');

// ── Partner spot claim (public, co-branded) ───────────────────────────────────
//
// Where a partner company's existing members take ownership of the position
// imported for them. Outside the `guest` group on purpose: somebody already
// signed in on a shared machine still has to be able to open the link their
// company emailed them, and bouncing them to a dashboard is a support call.
//
// Both POSTs are throttled hard. The verify step is an unauthenticated guess at
// a credential, and the ids it is guessed against are public knowledge — see
// SpotClaimService for why the per-spot lockout matters more than this does.
Route::prefix('partner')->name('partner.')->group(function () {
    Route::get('/{slug}',          [PartnerClaimController::class, 'show'])->name('claim');
    Route::post('/{slug}/verify',  [PartnerClaimController::class, 'verify'])
        ->middleware('throttle:10,1')->name('claim.verify');
    Route::get('/{slug}/details',  [PartnerClaimController::class, 'details'])->name('claim.details');
    Route::post('/{slug}/details', [PartnerClaimController::class, 'store'])
        ->middleware('throttle:10,1')->name('claim.store');
    // Somebody already signed in folding this position into the account they
    // have. Requires a verified session on both sides — see the controller.
    Route::post('/{slug}/merge',   [PartnerClaimController::class, 'merge'])
        ->middleware(['auth', 'throttle:10,1'])->name('claim.merge');
});

// ── Vendor storefront (public, member-coded) ──────────────────────────────────
//
// The customer-facing page a partner shares to sell a third-party product. No
// auth: the visitor is the partner's prospect, not a user of this application.
//
// The fixed segments come first so /p/handoff/… and /p/thanks/… can never be
// swallowed by the {code} pattern. The enquiry POST is throttled because it is
// an unauthenticated write that creates a row and an outbound email address.
Route::prefix('p')->name('vendor.')->group(function () {
    Route::get('/handoff/{reference}', [VendorStorefrontController::class, 'handoff'])->name('handoff');
    Route::get('/thanks/{reference}',  [VendorStorefrontController::class, 'thanks'])->name('thanks');

    // Stage two of a direct order: address, quote, payment. Throttled because
    // it is an unauthenticated write that triggers a tax API call.
    Route::get('/order/{reference}',           [VendorStorefrontController::class, 'order'])->name('order');
    Route::post('/order/{reference}/address',  [VendorStorefrontController::class, 'saveAddress'])
        ->middleware('throttle:20,1')->name('order.address');
    // The buyer's answer to the address check: FedEx's correction, or as entered.
    Route::post('/order/{reference}/address/confirm', [VendorStorefrontController::class, 'addressChoice'])
        ->middleware('throttle:20,1')->name('order.address.choice');
    Route::post('/order/{reference}/pay',      [VendorStorefrontController::class, 'pay'])
        ->middleware('throttle:10,1')->name('order.pay');
    Route::get('/complete/{reference}',        [VendorStorefrontController::class, 'complete'])->name('complete');

    Route::get('/{code}/{vendor}/{product}',  [VendorStorefrontController::class, 'show'])->name('product');
    Route::post('/{code}/{vendor}/{product}', [VendorStorefrontController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('enquire');
});

// User auth (guests only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [UserAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [UserAuthController::class, 'login'])->name('login.post');
    Route::get('/register', [UserAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [UserAuthController::class, 'register'])->name('register.post');

    // Forgot password. The names are Laravel's: the reset email builds its
    // link from route('password.reset').
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')->name('password.update');
});

// User logout
Route::post('/logout', [UserAuthController::class, 'logout'])->name('logout')->middleware('auth');

// ── Member area ───────────────────────────────────────────────────────────────

// ── Billing ───────────────────────────────────────────────────────────────────
//
// DELIBERATELY OUTSIDE the `subscribed` gate, and declared before the guarded
// group so it cannot be swept into it by a later edit.
//
// RequireActiveSubscription redirects an unsubscribed partner to
// member.billing.start. If these routes were also gated, that redirect would hit
// the gate again and bounce forever — the user can never reach the screen that
// would fix their state. Anything added here must stay ungated for the same
// reason; if a new billing route genuinely needs the gate, it belongs in the
// group below instead.
Route::prefix('member/billing')->name('member.billing.')->middleware('auth')->group(function () {
    // Card writes are throttled per partner. Every attempt checks a card with
    // the provider, and an unthrottled card form is how stolen card numbers get
    // tested against a live account.
    Route::get('/start',        [MemberBillingController::class, 'start'])->name('start');
    Route::post('/setup-intent', [MemberBillingController::class, 'setupIntent'])->middleware('throttle:10,60')->name('setup-intent');
    Route::post('/confirm',     [MemberBillingController::class, 'confirm'])->middleware('throttle:10,60')->name('confirm');

    Route::get('/',             [MemberBillingController::class, 'index'])->name('index');
    Route::post('/card',        [MemberBillingController::class, 'replaceCard'])->middleware('throttle:10,60')->name('card');
    Route::delete('/card/{paymentMethod}', [MemberBillingController::class, 'removeCard'])->name('card.remove');
    Route::post('/cancel',      [MemberBillingController::class, 'cancel'])->name('cancel');
    Route::post('/resume',      [MemberBillingController::class, 'resume'])->name('resume');
    Route::post('/start-now',   [MemberBillingController::class, 'startNow'])->middleware('throttle:10,60')->name('start-now');
});

// Also outside the gate: a partner held at card capture still needs to fix their
// profile and ask for help.
Route::prefix('member')->name('member.')->middleware('auth')->group(function () {
    // The Training Program section — what the membership is and how to get it.
    // Outside the subscription gate for the same reason Billing is: a page
    // about buying access cannot sit behind having bought it. While
    // `enrollment_open` is false this is an announcement, not a checkout.
    Route::get('/training-program',          [TrainingProgramController::class, 'show'])->name('training-program');
    Route::post('/training-program/interest', [TrainingProgramController::class, 'interest'])
        ->middleware('throttle:20,10')->name('training-program.interest');

    Route::get('/profile',           [MemberController::class, 'profile'])->name('profile');
    Route::post('/profile',               [MemberController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/address',       [MemberController::class, 'updateAddress'])->name('profile.address');
    Route::delete('/profile/photo',       [MemberController::class, 'removePhoto'])->name('profile.photo.remove');
    Route::post('/profile/password',      [MemberController::class, 'updatePassword'])->name('profile.password');

    // Support tickets
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/',                [MemberSupportController::class, 'index'])->name('index');
        Route::post('/',               [MemberSupportController::class, 'store'])->name('store');
        Route::get('/{ticket}',        [MemberSupportController::class, 'show'])->name('show');
        Route::post('/{ticket}/reply', [MemberSupportController::class, 'reply'])->name('reply');
    });
});

// Everything else in the member area needs a card on file, pre-launch included.
// A trialing subscription counts, so a partner is let in as soon as card capture
// succeeds and is not charged until the trial ends.
Route::prefix('member')->name('member.')->middleware(['auth', 'subscribed'])->group(function () {
    Route::get('/dashboard',         [MemberController::class, 'dashboard'])->name('dashboard');
    Route::get('/network',           [MemberController::class, 'network'])->name('network');
    // Imported positions in this partner's organisation that are still waiting
    // on their owner. Kept off the team page for the reason in the action.
    Route::get('/network/spots',     [MemberController::class, 'spots'])->name('network.spots');
    // The training library.
    //
    // 'training.visible' is the outermost gate and it 404s: while the library
    // is admin-only, production behaves as though these routes do not exist.
    // Admins pass every gate here — 'subscribed' and 'training.unlocked' both
    // exempt them — so an administrator previews the library on live exactly as
    // a member will see it, before anyone else can reach it.
    //
    // 'training.unlocked' holds out partners who chose to wait for commissions
    // before paying: they have not bought the program yet.
    //
    // 'opportunity:training' is the newest of the three and the coarsest: a
    // partner who joined through the PlasmaGuard site to sell air purification
    // systems never bought the training program, so for them it does not exist
    // at all. See config/opportunities.php.
    Route::middleware(['training.visible', 'opportunity:training', 'training.unlocked'])->group(function () {
        Route::get('/training',                  [MemberController::class, 'trainingIndex'])->name('training');
        Route::get('/training/c/{slug}',         [MemberController::class, 'trainingCategory'])->name('training.category');
        Route::get('/training/l/{slug}',         [MemberController::class, 'trainingLesson'])->name('training.lesson');

        // The media itself. Each of these re-checks role and release date for
        // the lesson the block belongs to, so a copied URL stops working when
        // the membership does. Nothing under here is ever a static file URL.
        Route::get('/training/video/{block}',    [TrainingMediaController::class, 'video'])->name('training.video');
        Route::get('/training/poster/{block}',   [TrainingMediaController::class, 'poster'])->name('training.poster');
        Route::get('/training/download/{block}', [TrainingMediaController::class, 'download'])->name('training.download');
    });
    Route::get('/referrals',         [MemberController::class, 'referral'])->name('referrals');

    // Get Paid: the Stripe Connect account a partner's commissions are paid
    // into. The POSTs call Stripe, so they are throttled per partner.
    Route::prefix('payouts')->name('payouts.')->group(function () {
        Route::get('/',                 [MemberPayoutController::class, 'index'])->name('index');
        Route::post('/account-session', [MemberPayoutController::class, 'accountSession'])->middleware('throttle:30,10')->name('account-session');
        Route::post('/refresh',         [MemberPayoutController::class, 'refresh'])->middleware('throttle:20,10')->name('refresh');
        Route::get('/hosted',           [MemberPayoutController::class, 'hosted'])->middleware('throttle:10,10')->name('hosted');
    });

    // Commissions portal
    Route::prefix('commissions')->name('commissions.')->group(function () {
        Route::get('/',         [MemberController::class, 'commissions'])->name('index');
        Route::get('/history',  [MemberController::class, 'commissionHistory'])->name('history');
        Route::get('/payouts',  [MemberController::class, 'commissionPayouts'])->name('payouts');
    });

    // Vendor product sales — the partner's own leads and share links, ordering
    // for themselves, and the running promotion. The fixed segments come before
    // {lead} so /sales/promotion is never read as a lead id.
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/',            [MemberVendorLeadController::class, 'index'])->name('index');
        Route::get('/promotion',   [MemberVendorLeadController::class, 'promotion'])->name('promotion');
        Route::get('/buy/{vendor}/{product}',  [MemberVendorLeadController::class, 'buy'])->name('buy');
        Route::post('/buy/{vendor}/{product}', [MemberVendorLeadController::class, 'placeOwnOrder'])
            ->middleware('throttle:10,1')->name('buy.store');
        Route::get('/{lead}',      [MemberVendorLeadController::class, 'show'])->name('show');
    });

    // CRM workspace
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('/', [MemberCrmController::class, 'dashboard'])->name('dashboard');

        // Contacts
        Route::get('/contacts',              [MemberCrmController::class, 'index'])->name('contacts.index');
        Route::get('/contacts/create',       [MemberCrmController::class, 'create'])->name('contacts.create');
        Route::post('/contacts',             [MemberCrmController::class, 'store'])->name('contacts.store');
        Route::get('/contacts/{contact}',    [MemberCrmController::class, 'show'])->name('contacts.show');
        Route::get('/contacts/{contact}/edit', [MemberCrmController::class, 'edit'])->name('contacts.edit');
        Route::put('/contacts/{contact}',    [MemberCrmController::class, 'update'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [MemberCrmController::class, 'destroy'])->name('contacts.destroy');

        // Notes
        Route::post('/contacts/{contact}/notes',           [MemberCrmController::class, 'storeNote'])->name('contacts.notes.store');
        Route::delete('/contacts/{contact}/notes/{note}',  [MemberCrmController::class, 'destroyNote'])->name('contacts.notes.destroy');

        // Follow-ups
        Route::post('/contacts/{contact}/followups',          [MemberCrmController::class, 'storeFollowup'])->name('contacts.followups.store');
        Route::post('/followups/{followup}/complete',         [MemberCrmController::class, 'completeFollowup'])->name('followups.complete');
        Route::delete('/followups/{followup}',                [MemberCrmController::class, 'destroyFollowup'])->name('followups.cancel');
    });

    // Presentations and funnels from the host's side: Your Rooms, prospects,
    // reports. Admin-only until PRESENTATIONS_OPEN_TO_MEMBERS=true — see
    // RequirePresentationAccess. A funnel is presentations, so same gate.
    Route::prefix('funnels')->name('funnels.')->middleware(['presentations', 'opportunity:video-flows'])->group(function () {
        Route::get('/',          [MemberFunnelController::class, 'index'])->name('index');
        Route::get('/{funnel}',  [MemberFunnelController::class, 'show'])->name('show');
    });

    Route::prefix('presentations')->name('presentations.')->middleware(['presentations', 'opportunity:presentations'])->group(function () {
        Route::get('/', [MemberPresentationController::class, 'index'])->name('index');

        // A member scheduling a released recording for their own team. Above
        // the {presentation} routes so "create" is not read as a slug.
        Route::get('/create', [MemberPresentationController::class, 'create'])->name('create');
        Route::post('/', [MemberPresentationController::class, 'store'])->name('store');

        // Every room at once. Above {presentation} so "live" is not a slug.
        Route::get('/prospects', [MemberPresentationController::class, 'prospects'])->name('prospects');
        Route::post('/prospects/{attendee}/crm', [MemberPresentationController::class, 'toCrm'])->name('prospects.crm');
        Route::get('/live', [MemberPresentationController::class, 'live'])->name('live');
        Route::get('/live/feed', [MemberPresentationController::class, 'liveFeed'])->name('live.feed');

        // Threads are keyed by the guest alone, so both consoles share them.
        Route::get('/thread/{attendee}', [MemberPresentationController::class, 'thread'])->name('thread');
        Route::post('/thread/{attendee}', [MemberPresentationController::class, 'reply'])->name('reply');
        Route::post('/thread/{attendee}/read', [MemberPresentationController::class, 'markRead'])->name('read');
        Route::get('/{presentation}', [MemberPresentationController::class, 'show'])->name('show');
        Route::get('/{presentation}/attendees', [MemberPresentationController::class, 'attendees'])->name('attendees');
        Route::get('/{presentation}/export', [MemberPresentationController::class, 'export'])->name('export');
        Route::get('/{presentation}/video', [MemberPresentationController::class, 'video'])->name('video');
        Route::delete('/{presentation}', [MemberPresentationController::class, 'destroy'])->name('destroy');
    });
});

// ── Product Partner portal ────────────────────────────────────────────────────
//
// A vendor whose products our partners sell, looking at their own line: how it
// is selling, how big the channel behind it is, and the account between us.
//
// Its own section on purpose. These are outside companies with a login, so they
// get neither the member area (they are not members and must never be asked for
// a card) nor the admin area (they are not staff). The section is where the
// tooling for helping them close deals will hang off.
//
// Everything inside is scoped by App\Support\ProductPartner — a route here
// reaching vendor_leads without going through it is a bug, not a shortcut.
Route::prefix('product-partner')->name('product-partner.')
    ->middleware(['auth', 'product_partner'])
    ->group(function () {
        Route::get('/', [PartnerDashboardController::class, 'index'])->name('dashboard');

        // Confirmed orders, in full: they are the merchant of record on these.
        Route::get('/sales',          [PartnerSalesController::class, 'index'])->name('sales');
        Route::get('/sales/export',   [PartnerSalesController::class, 'export'])->name('sales.export');
        Route::get('/sales/{vendorLead}', [PartnerSalesController::class, 'show'])->name('sales.show');

        // The pipeline in numbers only — see the controller for why.
        Route::get('/prospects',      [PartnerProspectController::class, 'index'])->name('prospects');

        // What is owed and what has been paid, one copy, both sides.
        Route::get('/statement',      [PartnerStatementController::class, 'index'])->name('statement');
        Route::post('/statement/payments', [PartnerStatementController::class, 'storePayment'])
            ->middleware('throttle:20,10')->name('statement.payments');
    });

/*
 * Where I land when I sign in.
 *
 * Outside every section on purpose: it is set from the profile menu of the
 * admin panel, the member area and the partner portal alike, and it is the one
 * thing an account holding several of them has to be able to change from
 * wherever it happens to be.
 */
Route::post('/preferences/landing', [LandingPreferenceController::class, 'update'])
    ->middleware('auth')->name('preferences.landing');

// Legacy /dashboard redirect. Follows the account's own choice rather than
// assuming the member area — an admin or a product partner landing here has
// somewhere of their own to be.
Route::get('/dashboard', fn() => redirect(request()->user()->landingRoute()))->middleware('auth');

// ── Recording playback ────────────────────────────────────────────────────────
//
// Deliberately outside the auth middleware: a recording set to "anyone with the
// link" has to open for a signed-out prospect. Every action re-checks
// ScreenRecording::viewableBy(), which treats a null user as the public, so the
// gate lives with the recording rather than with the route.
Route::prefix('recordings')->name('recordings.')->group(function () {
    Route::get('/{recording}',        [ScreenRecordingPlaybackController::class, 'watch'])->name('watch');
    Route::get('/{recording}/stream', [ScreenRecordingPlaybackController::class, 'stream'])->name('stream');
    Route::get('/{recording}/poster', [ScreenRecordingPlaybackController::class, 'poster'])->name('poster');
});

// ── Scheduled presentations (guests) ──────────────────────────────────────────
//
// Public by necessity: a guest has no account and never gets one here. Their
// identity is a token in a cookie, issued when they register. The optional
// {code} is the inviting member's referral code and is the only thing that
// attributes a guest to a member.
Route::prefix('watch')->name('presentations.')->group(function () {
    // Fixed segments first: {code?} is a catch-all and would otherwise swallow
    // /state as if it were somebody's referral code.
    Route::get('/{presentation}/state',     [PresentationWatchController::class, 'state'])->name('state');
    Route::post('/{presentation}/register', [PresentationWatchController::class, 'register'])->name('register');
    Route::post('/{presentation}/heartbeat',[PresentationWatchController::class, 'heartbeat'])->name('heartbeat');
    Route::post('/{presentation}/cta',      [PresentationWatchController::class, 'cta'])->name('cta');
    Route::post('/{presentation}/choose',   [PresentationWatchController::class, 'choose'])->name('choose');
    Route::get('/{presentation}/video',     [PresentationWatchController::class, 'video'])->name('video');
    Route::get('/{presentation}/messages',  [PresentationWatchController::class, 'messages'])->name('messages');
    Route::post('/{presentation}/messages', [PresentationWatchController::class, 'sendMessage'])->name('messages.send');
    Route::get('/{presentation}/{code?}',   [PresentationWatchController::class, 'show'])->name('watch');
});

// ── Funnel presentations (guests) ─────────────────────────────────────────────
//
// The way into a flow of videos. Public for the same reason /watch is: the
// person coming through has no account. The {code} is not optional in practice
// — a participant with no inviting member is refused — but it is optional in
// the route so that a returning guest's cookie can carry them without it.
Route::prefix('flow')->name('funnels.')->group(function () {
    Route::post('/{funnel}/register', [FunnelWatchController::class, 'register'])->name('register');
    Route::get('/{funnel}/{code?}',   [FunnelWatchController::class, 'enter'])->name('enter');
});

// ── Admin ─────────────────────────────────────────────────────────────────────

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'showLogin'])->name('auth.login')->middleware('guest');
    Route::post('login', [AdminAuthController::class, 'login'])->name('auth.login.post')->middleware('guest');
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('auth.logout');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Users — support admins can view/edit, only super admin can delete
        Route::resource('users', UserController::class);
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

        // Which business lines a member holds — see config/opportunities.php.
        // This decides what they see and whether a card is asked for, so it is
        // a deliberate staff action rather than something edited inline on the
        // user form.
        Route::post('users/{user}/opportunities', [UserController::class, 'addOpportunity'])->name('users.opportunities.add');
        Route::delete('users/{user}/opportunities/{opportunity}', [UserController::class, 'removeOpportunity'])->name('users.opportunities.remove');

        /*
         * Product partners: the vendors' own people and what they may see.
         *
         * Super admin only. Granting one of these is letting an outside company
         * see our sales pipeline, which is a commercial decision rather than a
         * support action — support staff can see the list, not change it.
         */
        Route::prefix('product-partners')->name('product-partners.')->group(function () {
            Route::get('/', [ProductPartnerController::class, 'index'])->name('index');

            /*
             * Look at the portal as one particular partner sees it. Read-only,
             * and any admin may: it shows them no more than the admin section
             * already does, only arranged as the vendor sees it.
             *
             * `stop-viewing` is one segment and `{user}/view-as` is two, so the
             * two patterns cannot collide.
             */
            Route::post('/stop-viewing', [ProductPartnerController::class, 'stopViewingAs'])->name('stop-viewing');
            Route::post('/{user}/view-as', [ProductPartnerController::class, 'viewAs'])->name('view-as');

            // What the vendors say they have paid us, waiting on our agreement.
            Route::get('/payments', [ProductPartnerController::class, 'payments'])->name('payments');
            Route::post('/payments/{payment}/confirm', [ProductPartnerController::class, 'confirmPayment'])
                ->middleware('super_admin')->name('payments.confirm');
            Route::post('/payments/{payment}/reject', [ProductPartnerController::class, 'rejectPayment'])
                ->middleware('super_admin')->name('payments.reject');
        });

        /*
         * Whether a product partner also sells. Super admin only, like the
         * product grant: it hands an outside company's employee a back office,
         * a referral code and a commission ledger entry of their own.
         */
        Route::post('users/{user}/product-partner/member-access', [ProductPartnerController::class, 'grantMemberAccess'])
            ->middleware('super_admin')->name('users.product-partner.member-access.add');
        Route::delete('users/{user}/product-partner/member-access', [ProductPartnerController::class, 'revokeMemberAccess'])
            ->middleware('super_admin')->name('users.product-partner.member-access.remove');

        Route::post('users/{user}/product-partner', [ProductPartnerController::class, 'store'])
            ->middleware('super_admin')->name('users.product-partner.add');
        Route::delete('users/{user}/product-partner/{assignment}', [ProductPartnerController::class, 'destroy'])
            ->middleware('super_admin')->name('users.product-partner.remove');

        Route::prefix('sponsors')->name('sponsors.')->group(function () {
            Route::get('/', [SponsorController::class, 'index'])->name('index');
            Route::get('relationships', [SponsorController::class, 'relationships'])->name('relationships');
        });

        // ── Partner companies, their imports, and the spot board ──────────────
        //
        // Committing an import writes permanent genealogy, and reissuing an
        // activation code hands over control of a position, so the whole
        // section is super-admin only. Support admins get the read-only board
        // through the same screens once there is a reason to split it.
        Route::prefix('partners')->name('partners.')->middleware('super_admin')->group(function () {
            Route::get('/', [HoldingSpotController::class, 'index'])->name('spots');
            Route::post('/spots/{spot}/reissue', [HoldingSpotController::class, 'reissue'])->name('spots.reissue');
            // Rearranges a live genealogy. The only action in this module that
            // does — see SpotMergeService.
            Route::post('/spots/{spot}/merge',   [HoldingSpotController::class, 'merge'])->name('spots.merge');

            // Who came through the claim page and what they have sold since.
            // 'export' before nothing else here, but kept above in the file for
            // the same reason the webhook guide is: a literal segment must not
            // end up read as an id if this prefix ever grows one.
            Route::get('/activations/export', [PartnerActivationController::class, 'export'])->name('activations.export');
            Route::get('/activations',        [PartnerActivationController::class, 'index'])->name('activations');

            Route::prefix('companies')->name('companies.')->group(function () {
                Route::get('/',               [PartnerCompanyController::class, 'index'])->name('index');
                Route::get('/create',         [PartnerCompanyController::class, 'create'])->name('create');
                Route::post('/',              [PartnerCompanyController::class, 'store'])->name('store');
                Route::get('/{company}/edit', [PartnerCompanyController::class, 'edit'])->name('edit');
                Route::put('/{company}',      [PartnerCompanyController::class, 'update'])->name('update');
                Route::post('/{company}/ping', [PartnerWebhookController::class, 'ping'])->name('ping');
            });

            // What we told each partner, and what they said back.
            Route::prefix('webhooks')->name('webhooks.')->group(function () {
                Route::get('/',                    [PartnerWebhookController::class, 'index'])->name('index');
                // Before /{delivery} so "guide" cannot be read as an id.
                Route::get('/guide',               [PartnerWebhookController::class, 'guide'])->name('guide');
                Route::get('/troubleshooting',     [PartnerWebhookController::class, 'troubleshooting'])->name('troubleshooting');
                Route::get('/{delivery}',          [PartnerWebhookController::class, 'show'])->name('show');
                Route::post('/{delivery}/replay',  [PartnerWebhookController::class, 'replay'])->name('replay');
            });

            Route::prefix('imports')->name('imports.')->group(function () {
                // Declared before /{import} so the word "template" can never be
                // read as an import id.
                Route::get('/template',   [SpotImportController::class, 'template'])->name('template');
                Route::get('/',           [SpotImportController::class, 'index'])->name('index');
                Route::post('/',          [SpotImportController::class, 'store'])->name('store');
                Route::get('/{import}',   [SpotImportController::class, 'show'])->name('show');
                Route::post('/{import}/rows/{row}/link', [SpotImportController::class, 'link'])->name('link');
                Route::post('/{import}/revalidate',      [SpotImportController::class, 'revalidate'])->name('revalidate');
                Route::post('/{import}/commit',          [SpotImportController::class, 'commit'])->name('commit');
            });
        });

        Route::prefix('error-logs')->name('error-logs.')->group(function () {
            Route::get('/', [ErrorLogController::class, 'index'])->name('index');
            Route::get('{errorLog}', [ErrorLogController::class, 'show'])->name('show');
            Route::patch('{errorLog}', [ErrorLogController::class, 'update'])->name('update');
            Route::delete('{errorLog}', [ErrorLogController::class, 'destroy'])->name('destroy');
            Route::post('bulk-resolve', [ErrorLogController::class, 'bulkResolve'])->name('bulk-resolve');
        });

        // Roles management — super admin only
        Route::prefix('roles')->name('roles.')->middleware('super_admin')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
        });

        // Site settings — super admin only
        Route::prefix('settings')->name('settings.')->middleware('super_admin')->group(function () {
            Route::get('/', [SiteSettingController::class, 'index'])->name('index');
            Route::post('/', [SiteSettingController::class, 'update'])->name('update');
            Route::delete('logo', [SiteSettingController::class, 'removeLogo'])->name('logo.remove');
        });

        // Support tickets
        Route::prefix('support')->name('support.')->group(function () {
            Route::get('/',                         [SupportTicketController::class, 'index'])->name('index');
            Route::get('/{ticket}',                 [SupportTicketController::class, 'show'])->name('show');
            Route::post('/{ticket}/reply',          [SupportTicketController::class, 'reply'])->name('reply');
            Route::patch('/{ticket}/status',        [SupportTicketController::class, 'updateStatus'])->name('status');
        });

        // Billing oversight (C3)
        Route::prefix('billing')->name('billing.')->group(function () {
            Route::get('/subscriptions', [AdminBillingController::class, 'subscriptions'])->name('subscriptions');
            Route::post('/subscriptions/{subscription}/sync', [AdminBillingController::class, 'sync'])->name('subscriptions.sync');
            Route::post('/subscriptions/{subscription}/extend-trial', [AdminBillingController::class, 'extendTrial'])->name('subscriptions.extend-trial');
            Route::post('/subscriptions/{subscription}/start-billing', [AdminBillingController::class, 'startBilling'])->name('subscriptions.start-billing');

            Route::get('/webhooks', [AdminBillingController::class, 'webhooks'])->name('webhooks');
            Route::post('/webhooks/{event}/replay', [AdminBillingController::class, 'replay'])->name('webhooks.replay');

            // Partners' Stripe payout accounts: who is missing what.
            Route::prefix('payout-accounts')->name('payout-accounts.')->group(function () {
                Route::get('/',                [ConnectAccountController::class, 'index'])->name('index');
                Route::post('/sync-all',       [ConnectAccountController::class, 'syncAll'])->name('sync-all');
                Route::post('/request-all',    [ConnectAccountController::class, 'requestInformationBulk'])->name('request-all');
                Route::post('/{user}/sync',    [ConnectAccountController::class, 'sync'])->name('sync');
                Route::post('/{user}/request', [ConnectAccountController::class, 'requestInformation'])->name('request');
            });
        });

        // Vendor referral oversight — attribution, reconciliation, and the
        // export that tells the vendor who actually placed each order.
        Route::prefix('vendor-leads')->name('vendor-leads.')->group(function () {
            Route::get('/',                  [VendorLeadController::class, 'index'])->name('index');
            Route::get('/export',            [VendorLeadController::class, 'export'])->name('export');

            // What the vendor owes us. Under the direct-key arrangement nothing
            // is taken at the point of sale, so this is how the revenue share
            // actually gets collected.
            Route::get('/reconciliation',    [VendorLeadController::class, 'reconciliation'])->name('reconciliation');
            // The running sales promotion: every order holding a place.
            Route::get('/promotion',         [VendorLeadController::class, 'promotion'])->name('promotion');
            Route::post('/invoice',          [VendorLeadController::class, 'invoice'])->name('invoice');
            Route::post('/settle',           [VendorLeadController::class, 'settle'])->name('settle');
            Route::post('/{vendorLead}/fulfil', [VendorLeadController::class, 'fulfil'])->name('fulfil');
            Route::get('/{vendorLead}',      [VendorLeadController::class, 'show'])->name('show');
            Route::post('/{vendorLead}/convert', [VendorLeadController::class, 'convert'])->name('convert');
            Route::post('/{vendorLead}/lost',    [VendorLeadController::class, 'lost'])->name('lost');
            // Who an order counts for: a customer sale or a partner's own purchase.
            Route::post('/{vendorLead}/attribution', [VendorLeadController::class, 'attribution'])->name('attribution');
            // An admin checked a delivery address the buyer confirmed but FedEx did not.
            Route::post('/{vendorLead}/address-reviewed', [VendorLeadController::class, 'addressReviewed'])->name('address-reviewed');
        });

        // Commission system
        Route::resource('commission-plans', CommissionPlanController::class)
            ->names('commission-plans');

        Route::prefix('commission-ledger')->name('commission-ledger.')->group(function () {
            Route::get('/',              [CommissionLedgerController::class, 'index'])->name('index');
            Route::post('/',             [CommissionLedgerController::class, 'store'])->name('store');
            Route::post('{ledger}/approve', [CommissionLedgerController::class, 'approve'])->name('approve');
            Route::post('{ledger}/void',    [CommissionLedgerController::class, 'void'])->name('void');
        });

        Route::prefix('commission-payouts')->name('commission-payouts.')->group(function () {
            Route::get('/',                       [CommissionPayoutController::class, 'index'])->name('index');
            Route::get('/create',                 [CommissionPayoutController::class, 'create'])->name('create');
            Route::post('/',                      [CommissionPayoutController::class, 'store'])->name('store');
            Route::get('/{commissionPayout}',     [CommissionPayoutController::class, 'show'])->name('show');
            Route::post('/{commissionPayout}/approve',   [CommissionPayoutController::class, 'approve'])->name('approve');
            Route::post('/{commissionPayout}/mark-paid', [CommissionPayoutController::class, 'markPaid'])->name('mark-paid');
            Route::post('/{commissionPayout}/send-transfer',    [CommissionPayoutController::class, 'sendTransfer'])->name('send-transfer');
            Route::post('/{commissionPayout}/reverse-transfer', [CommissionPayoutController::class, 'reverseTransfer'])->name('reverse-transfer');
            Route::delete('/{commissionPayout}',         [CommissionPayoutController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('commission-clawbacks')->name('commission-clawbacks.')->group(function () {
            Route::get('/',                         [CommissionClawbackController::class, 'index'])->name('index');
            Route::post('/',                        [CommissionClawbackController::class, 'store'])->name('store');
            Route::get('/{commissionClawback}',     [CommissionClawbackController::class, 'show'])->name('show');
            Route::post('/{commissionClawback}/reverse', [CommissionClawbackController::class, 'reverse'])->name('reverse');
        });

        // CRM
        Route::prefix('crm')->name('crm.')->group(function () {
            Route::get('/', [CrmDashboardController::class, 'index'])->name('dashboard');

            // Contacts
            Route::get('/contacts',                    [CrmContactController::class, 'index'])->name('contacts.index');
            Route::get('/contacts/create',             [CrmContactController::class, 'create'])->name('contacts.create');
            Route::post('/contacts',                   [CrmContactController::class, 'store'])->name('contacts.store');
            Route::get('/contacts/{crmContact}',       [CrmContactController::class, 'show'])->name('contacts.show');
            Route::get('/contacts/{crmContact}/edit',  [CrmContactController::class, 'edit'])->name('contacts.edit');
            Route::put('/contacts/{crmContact}',       [CrmContactController::class, 'update'])->name('contacts.update');
            Route::delete('/contacts/{crmContact}',    [CrmContactController::class, 'destroy'])->name('contacts.destroy');

            // Notes (scoped to a contact)
            Route::post('/contacts/{crmContact}/notes',          [CrmActivityController::class, 'storeNote'])->name('contacts.notes.store');
            Route::delete('/contacts/{crmContact}/notes/{note}', [CrmActivityController::class, 'destroyNote'])->name('contacts.notes.destroy');

            // Follow-ups
            Route::post('/contacts/{crmContact}/followups',      [CrmActivityController::class, 'storeFollowup'])->name('contacts.followups.store');
            Route::post('/followups/{followup}/complete',        [CrmActivityController::class, 'completeFollowup'])->name('followups.complete');
            Route::post('/followups/{followup}/snooze',          [CrmActivityController::class, 'snoozeFollowup'])->name('followups.snooze');
            Route::delete('/followups/{followup}',               [CrmActivityController::class, 'destroyFollowup'])->name('followups.cancel');
        });

        // Training content management
        Route::prefix('training')->name('training.')->group(function () {
            Route::resource('categories', TrainingCategoryController::class);
            Route::resource('lessons', TrainingLessonController::class);
            Route::post('content-blocks',                  [TrainingContentBlockController::class, 'store'])->name('content-blocks.store');
            Route::put('content-blocks/{block}',           [TrainingContentBlockController::class, 'update'])->name('content-blocks.update');
            Route::delete('content-blocks/{block}',        [TrainingContentBlockController::class, 'destroy'])->name('content-blocks.destroy');
            Route::post('content-blocks/reorder',          [TrainingContentBlockController::class, 'reorder'])->name('content-blocks.reorder');
        });

        // Video library
        Route::prefix('video-assets')->name('video-assets.')->group(function () {
            Route::get('/',                                    [VideoAssetController::class, 'index'])->name('index');
            Route::get('/{videoAsset}',                        [VideoAssetController::class, 'show'])->name('show');
            Route::put('/{videoAsset}',                        [VideoAssetController::class, 'update'])->name('update');
            Route::delete('/{videoAsset}',                     [VideoAssetController::class, 'destroy'])->name('destroy');
            Route::post('/{videoAsset}/upload-vimeo',          [VideoAssetController::class, 'uploadToVimeo'])->name('upload-vimeo');
            Route::post('/{videoAsset}/assign-block',          [VideoAssetController::class, 'assignToBlock'])->name('assign-block');
        });

        // The library of calls to action, and the flows that place them.
        Route::resource('cta-items', AdminCtaItemController::class)->except(['show']);

        Route::prefix('funnels')->name('funnels.')->group(function () {
            Route::get('/',                [AdminFunnelController::class, 'index'])->name('index');
            Route::get('/create',          [AdminFunnelController::class, 'create'])->name('create');
            Route::post('/',               [AdminFunnelController::class, 'store'])->name('store');

            // Choices live on a video, not on the flow, so they are addressed
            // by presentation. Above {funnel} so "cues" is not read as a slug.
            Route::post('/cues/{presentation}',    [AdminFunnelController::class, 'addCue'])->name('cues.store');
            Route::patch('/cues/{cue}/toggle',     [AdminFunnelController::class, 'toggleCue'])->name('cues.toggle');
            Route::delete('/cues/{cue}',           [AdminFunnelController::class, 'destroyCue'])->name('cues.destroy');

            Route::get('/{funnel}',        [AdminFunnelController::class, 'show'])->name('show');
            Route::get('/{funnel}/prospects', [AdminFunnelController::class, 'prospects'])->name('prospects');
            Route::get('/{funnel}/edit',   [AdminFunnelController::class, 'edit'])->name('edit');
            Route::put('/{funnel}',        [AdminFunnelController::class, 'update'])->name('update');
            Route::delete('/{funnel}',     [AdminFunnelController::class, 'destroy'])->name('destroy');
            Route::post('/{funnel}/steps', [AdminFunnelController::class, 'addStep'])->name('steps.store');
            Route::patch('/{funnel}/steps/{presentation}/entry',  [AdminFunnelController::class, 'setEntry'])->name('steps.entry');
            Route::delete('/{funnel}/steps/{presentation}',       [AdminFunnelController::class, 'removeStep'])->name('steps.destroy');
        });

        // Scheduled presentations — showings of a library recording.
        Route::prefix('presentations')->name('presentations.')->group(function () {
            Route::get('/',                     [AdminPresentationController::class, 'index'])->name('index');
            Route::get('/create',               [AdminPresentationController::class, 'create'])->name('create');
            Route::get('/prospects',            [AdminPresentationController::class, 'prospects'])->name('prospects');

            // Repeating schedules. Above the {presentation} routes so "series"
            // is not swallowed as a slug.
            Route::prefix('series')->name('series.')->group(function () {
                Route::get('/',           [AdminPresentationSeriesController::class, 'index'])->name('index');
                Route::get('/create',     [AdminPresentationSeriesController::class, 'create'])->name('create');
                Route::post('/',          [AdminPresentationSeriesController::class, 'store'])->name('store');
                Route::get('/{series}/edit', [AdminPresentationSeriesController::class, 'edit'])->name('edit');
                Route::put('/{series}',   [AdminPresentationSeriesController::class, 'update'])->name('update');
                Route::delete('/{series}',[AdminPresentationSeriesController::class, 'destroy'])->name('destroy');
            });
            Route::post('/',                    [AdminPresentationController::class, 'store'])->name('store');
            Route::get('/{presentation}',       [AdminPresentationController::class, 'show'])->name('show');
            Route::get('/{presentation}/edit',  [AdminPresentationController::class, 'edit'])->name('edit');
            Route::put('/{presentation}',       [AdminPresentationController::class, 'update'])->name('update');
            Route::post('/{presentation}/start',[AdminPresentationController::class, 'start'])->name('start');
            Route::post('/{presentation}/end',  [AdminPresentationController::class, 'end'])->name('end');
            Route::post('/{presentation}/announce', [AdminPresentationController::class, 'announce'])->name('announce');
            Route::post('/{presentation}/chapters', [AdminPresentationController::class, 'saveChapters'])->name('chapters');
            Route::delete('/{presentation}',    [AdminPresentationController::class, 'destroy'])->name('destroy');
        });

        // Screen recording studio + video library.
        //
        // The chunk endpoint is hit dozens of times per recording while the
        // capture is still running, so it stays lean: no view, no eager loads.
        Route::prefix('screen-recordings')->name('screen-recordings.')->group(function () {
            Route::get('/',                          [ScreenRecordingController::class, 'index'])->name('index');
            Route::get('/studio',                    [ScreenRecordingController::class, 'studio'])->name('studio');
            Route::get('/upload',                    [ScreenRecordingController::class, 'uploadForm'])->name('upload');
            Route::get('/combine',                   [ScreenRecordingController::class, 'composeForm'])->name('combine');
            Route::post('/combine',                  [ScreenRecordingController::class, 'compose'])->name('combine.store');
            Route::post('/',                         [ScreenRecordingController::class, 'store'])->name('store');
            Route::post('/{recording}/chunk',        [ScreenRecordingController::class, 'chunk'])->name('chunk');
            Route::post('/{recording}/finalize',     [ScreenRecordingController::class, 'finalize'])->name('finalize');
            Route::post('/{recording}/abort',        [ScreenRecordingController::class, 'abort'])->name('abort');
            Route::get('/{recording}',               [ScreenRecordingController::class, 'show'])->name('show');
            Route::put('/{recording}',               [ScreenRecordingController::class, 'update'])->name('update');
            Route::post('/{recording}/retry-store',  [ScreenRecordingController::class, 'retryStore'])->name('retry-store');
            Route::post('/{recording}/trim',         [ScreenRecordingController::class, 'trim'])->name('trim');
            Route::post('/{recording}/trim/revert',  [ScreenRecordingController::class, 'revertTrim'])->name('trim.revert');
            Route::post('/{recording}/rebuild',      [ScreenRecordingController::class, 'rebuild'])->name('rebuild');
            Route::post('/{recording}/publish',      [ScreenRecordingController::class, 'publish'])->name('publish');
            Route::delete('/{recording}',            [ScreenRecordingController::class, 'destroy'])->name('destroy');
        });

        // Kartra import management
        Route::prefix('kartra')->name('kartra.')->group(function () {
            Route::get('/',                                    [KartraImportController::class, 'index'])->name('index');
            Route::get('/{kartraImport}',                      [KartraImportController::class, 'show'])->name('show');
            Route::patch('/{kartraImport}/map',                [KartraImportController::class, 'map'])->name('map');
            Route::delete('/{kartraImport}',                   [KartraImportController::class, 'destroy'])->name('destroy');
            Route::post('/download-videos',                    [KartraImportController::class, 'downloadVideos'])->name('download-videos');
            Route::post('/import-json',                        [KartraImportController::class, 'importJson'])->name('import-json');
            Route::post('/download-files',                     [KartraImportController::class, 'downloadFiles'])->name('download-files');
        });
    });
});
