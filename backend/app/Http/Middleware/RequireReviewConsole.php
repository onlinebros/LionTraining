<?php

namespace App\Http\Middleware;

use App\Services\RequestAssistant\Upstream;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Review Requests exists only on dev, once it is connected to the
 * server that keeps the requests. Requests are decided there and nowhere else.
 */
class RequireReviewConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Upstream::isConnected(), 404);

        return $next($request);
    }
}
