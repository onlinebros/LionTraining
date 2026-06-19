<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->isSuperAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden. Super Admin only.'], 403);
            }
            abort(403, 'Access denied. Super Admin only.');
        }

        return $next($request);
    }
}
