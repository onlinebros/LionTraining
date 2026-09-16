<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the training program closed to partners who chose to wait for their
 * commissions before paying. It opens as soon as their billing starts, either
 * when they reach the threshold or when they choose to start now from the
 * billing screen.
 */
class EnsureTrainingUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isOnCommissionHold()) {
            return $next($request);
        }

        $message = 'The training program opens once your membership billing starts. Start now below, or it starts automatically when your paid commissions reach $'
            . number_format((float) config('stripe.subscription.commission_threshold', 200)) . '.';

        if ($request->expectsJson()) {
            return response()->json([
                'message'  => $message,
                'redirect' => route('member.billing.index'),
            ], 403);
        }

        return redirect()->route('member.billing.index')->with('status', $message);
    }
}
