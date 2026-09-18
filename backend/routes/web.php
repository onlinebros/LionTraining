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
use App\Http\Controllers\UserAuthController;
use App\Http\Controllers\VendorStorefrontController;
use App\Http\Controllers\MemberVendorLeadController;
use App\Http\Controllers\Admin\VendorLeadController;
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
    // Partners waiting on commissions before paying don't get training yet.
    Route::middleware('training.unlocked')->group(function () {
        Route::get('/training',                  [MemberController::class, 'trainingIndex'])->name('training');
        Route::get('/training/c/{slug}',         [MemberController::class, 'trainingCategory'])->name('training.category');
        Route::get('/training/l/{slug}',         [MemberController::class, 'trainingLesson'])->name('training.lesson');
        Route::get('/training/download/{block}', [MemberController::class, 'trainingDownload'])->name('training.download');
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
});

// Legacy /dashboard redirect
Route::get('/dashboard', fn() => redirect()->route('member.dashboard'))->middleware('auth');

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
