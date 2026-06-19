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
use App\Http\Controllers\Admin\SponsorController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\TrainingCategoryController;
use App\Http\Controllers\Admin\TrainingContentBlockController;
use App\Http\Controllers\Admin\TrainingLessonController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VideoAssetController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberCrmController;
use App\Http\Controllers\MemberSupportController;
use App\Http\Controllers\UserAuthController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', fn() => view('public.home'))->name('home');

// Referral sign-up (public, no auth required, before guest middleware)
Route::get('/join/{code}', [UserAuthController::class, 'showReferral'])->name('join');
Route::post('/join/{code}', [UserAuthController::class, 'registerViaReferral'])->name('join.post');

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

Route::prefix('member')->name('member.')->middleware('auth')->group(function () {
    Route::get('/dashboard',         [MemberController::class, 'dashboard'])->name('dashboard');
    Route::get('/network',           [MemberController::class, 'network'])->name('network');
    Route::get('/training',                      [MemberController::class, 'trainingIndex'])->name('training');
    Route::get('/training/c/{slug}',             [MemberController::class, 'trainingCategory'])->name('training.category');
    Route::get('/training/l/{slug}',             [MemberController::class, 'trainingLesson'])->name('training.lesson');
    Route::get('/training/download/{block}',     [MemberController::class, 'trainingDownload'])->name('training.download');
    Route::get('/referrals',         [MemberController::class, 'referral'])->name('referrals');
    Route::get('/profile',           [MemberController::class, 'profile'])->name('profile');
    Route::post('/profile',               [MemberController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/address',       [MemberController::class, 'updateAddress'])->name('profile.address');
    Route::delete('/profile/photo',       [MemberController::class, 'removePhoto'])->name('profile.photo.remove');
    Route::post('/profile/password',      [MemberController::class, 'updatePassword'])->name('profile.password');

    // Commissions portal
    Route::prefix('commissions')->name('commissions.')->group(function () {
        Route::get('/',         [MemberController::class, 'commissions'])->name('index');
        Route::get('/history',  [MemberController::class, 'commissionHistory'])->name('history');
        Route::get('/payouts',  [MemberController::class, 'commissionPayouts'])->name('payouts');
    });

    // Support tickets
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/',                [MemberSupportController::class, 'index'])->name('index');
        Route::post('/',               [MemberSupportController::class, 'store'])->name('store');
        Route::get('/{ticket}',        [MemberSupportController::class, 'show'])->name('show');
        Route::post('/{ticket}/reply', [MemberSupportController::class, 'reply'])->name('reply');
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
        });
    });
});
