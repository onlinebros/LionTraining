<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'admin'       => \App\Http\Middleware\RequireAdminRole::class,
            'super_admin' => \App\Http\Middleware\RequireSuperAdmin::class,
            'active'      => \App\Http\Middleware\EnsureUserIsActive::class,
            'prelaunch'   => \App\Http\Middleware\PrelaunchGuard::class,
            'invitation'  => \App\Http\Middleware\RequireInvitation::class,
            'subscribed'  => \App\Http\Middleware\RequireActiveSubscription::class,
            'training.unlocked' => \App\Http\Middleware\EnsureTrainingUnlocked::class,
        ]);

        // Check is_active on every authenticated web request
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureUserIsActive::class);

        // The pre-launch guard runs on every web request rather than being
        // attached per route group. Sections are closed by route-name prefix in
        // config/prelaunch.php, so a route added to a closed section later is
        // closed the moment it exists — with a per-group attachment it would be
        // open until someone remembered to wrap it.
        $middleware->appendToGroup('web', \App\Http\Middleware\PrelaunchGuard::class);

        // Invitation-only registration, closed by route name in
        // config/registration.php. On both groups for the same reason the
        // pre-launch guard is on web: the API signup endpoint is a second front
        // door, and a control that only covers the HTML form is not a control.
        $middleware->appendToGroup('web', \App\Http\Middleware\RequireInvitation::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\RequireInvitation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->report(function (\Throwable $e) {
            // Skip routine non-error HTTP exceptions (404, 403, validation, auth)
            if ($e instanceof \Illuminate\Auth\AuthenticationException ||
                $e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                return false;
            }

            try {
                $request = request();
                \App\Models\ErrorLog::create([
                    'level'   => 'error',
                    'message' => $e->getMessage() ?: get_class($e),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                    'url'     => $request?->fullUrl(),
                    'method'  => $request?->method(),
                    'context' => [
                        'exception' => get_class($e),
                        'code'      => $e->getCode(),
                    ],
                    'user_id' => auth()->id(),
                    'status'  => 'new',
                ]);
            } catch (\Throwable) {
                // Never let logging crash the app
            }

            return false; // continue normal Laravel reporting
        });
    })->create();
