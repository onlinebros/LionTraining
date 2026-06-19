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
        ]);

        // Check is_active on every authenticated web request
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureUserIsActive::class);
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
