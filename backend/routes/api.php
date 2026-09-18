<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SponsorController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Stripe webhooks — signature-verified, idempotency-keyed. No auth (Stripe is
// the caller); throttled to slow brute-force probing of the signing secret.
//
// Two endpoints with two secrets. Platform-account events and connected-account
// events (payouts) are signed with different secrets, and each must be verified
// against its own — a Connect event presented here has to fail verification, or
// the separation is decorative.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.stripe');

Route::post('/webhooks/stripe/connect', [StripeWebhookController::class, 'handleConnect'])
    ->middleware('throttle:120,1')
    ->name('webhooks.stripe.connect');

// Sale confirmations from a VENDOR's own Stripe account — a third-party company
// whose product our partners refer. One URL per vendor, each verified against
// that vendor's own signing secret, so a secret can only ever validate events
// for the vendor it belongs to.
//
// The slug is constrained to the registry's shape so the route cannot be used
// to probe for arbitrary config keys.
Route::post('/webhooks/vendor/{vendor}', [\App\Http\Controllers\Api\VendorWebhookController::class, 'handle'])
    ->where('vendor', '[a-z0-9\-]+')
    ->middleware('throttle:120,1')
    ->name('webhooks.vendor');

// Public auth endpoints — throttled to slow credential stuffing / signup abuse.
//
// Named so the invitation guard can address them: it closes routes by name
// (config/registration.php), and an unnamed route cannot be closed by a list.
// `register` is the general signup and is shut while the platform is invitation
// only; `sponsor-register` carries a sponsor and stays open.
Route::prefix('auth')->name('api.auth.')->middleware('throttle:10,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/sponsor-register', [SponsorController::class, 'registerWithSponsor'])->name('sponsor-register');
});

// Public contact form on the company website → anonymous support ticket.
//
// Stateless on purpose. The site posts same-origin from a Sanctum stateful
// domain, and the stateful middleware would start a session and demand a CSRF
// token (419) from a form that has neither. Nothing here authenticates anyone.
// Throttled by the named `support-requests` limiter (AppServiceProvider), not
// an inline throttle — inline limits key guests by IP alone and would share a
// bucket with the auth routes above.
Route::post('/support/requests', [\App\Http\Controllers\Api\PublicSupportRequestController::class, 'store'])
    ->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->middleware('throttle:support-requests')
    ->name('api.support.requests.store');

// Partner's personal website (q3.life/{code}) → the sponsor's name.
//
// Stateless for the same reason as the contact form. The code is constrained to
// the shape referral codes are generated in, so nothing else is looked up. Its
// own limiter, as above: the site calls it on page load, and sharing the auth
// bucket would let browsing lock someone out of logging in.
Route::get('/sponsors/{code}', [\App\Http\Controllers\Api\PublicSponsorController::class, 'show'])
    ->where('code', '[A-Za-z0-9]{8}')
    ->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->middleware('throttle:sponsor-lookup')
    ->name('api.sponsors.show');

// Authenticated endpoints — Sanctum's stateful middleware (prepended in
// bootstrap/app.php) attaches the web session when the request comes from a
// configured stateful domain, so `auth:sanctum` here resolves the user from
// the httpOnly session cookie. Deactivated accounts are blocked by the
// EnsureUserIsActive middleware that is appended to the web middleware group
// in bootstrap/app.php — it runs ahead of this once StartSession has booted.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::prefix('sponsor')->group(function () {
        Route::get('/sponsees', [SponsorController::class, 'mySponsees']);
        Route::get('/sponsors', [SponsorController::class, 'mySponsors']);
        Route::patch('/{sponsorship}/status', [SponsorController::class, 'updateSponsorshipStatus']);
    });
});
