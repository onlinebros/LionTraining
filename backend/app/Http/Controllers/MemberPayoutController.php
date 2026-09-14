<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Services\CommissionService;
use App\Services\Stripe\StripeClientFactory;
use App\Services\Stripe\StripeConnectService;
use App\Support\ConnectRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Get Paid: where a partner sets up the Stripe account commissions are paid into.
 *
 * Onboarding runs inside this page through Stripe's embedded components, so a
 * partner goes from "set up payouts" to "payouts enabled" without leaving the
 * back office. The Stripe-hosted Account Link is only a fallback for browsers
 * where the embedded component cannot run.
 */
class MemberPayoutController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $connect,
        private readonly StripeClientFactory $stripe,
    ) {}

    public function index(Request $request, CommissionService $commissions)
    {
        $user = $request->user();

        // Re-sync at most once a minute on page view; the account.updated
        // webhook covers everything in between.
        if ($this->connect->enabled() && $user->hasConnectAccount()
            && (! $user->connect_synced_at || $user->connect_synced_at->lt(now()->subMinute()))) {
            try {
                $this->connect->syncAccount($user);
                $user->refresh();
            } catch (BillingException $e) {
                session()->now('error', $e->getMessage());
            }
        }

        return view('member.payouts.index', [
            'user'           => $user,
            'publishableKey' => $this->stripe->publishableKey(),
            'connectEnabled' => $this->connect->enabled() && $this->stripe->isConfigured(),
            'hostedFallback' => (bool) config('stripe.connect.hosted_fallback'),
            'outstanding'    => ConnectRequirements::summarise($user->connectOutstandingRequirements()),
            'upcoming'       => ConnectRequirements::summarise($user->connectUpcomingRequirements()),
            // The concrete reason to finish setup, rather than an abstract nag.
            'unpaidBalance'  => $commissions->getBalance($user),
        ]);
    }

    /** A fresh Account Session secret for the embedded components. */
    public function accountSession(Request $request): JsonResponse
    {
        try {
            $session = $this->connect->createAccountSession($request->user());
        } catch (BillingException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (ApiErrorException $e) {
            Log::error('Account session create failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'We could not reach Stripe. Please try again.'], 502);
        }

        return response()->json(['client_secret' => $session->client_secret]);
    }

    /** Pull the latest status; also called when the partner leaves the onboarding form. */
    public function refresh(Request $request)
    {
        try {
            $this->connect->syncAccount($request->user());
        } catch (BillingException $e) {
            return $request->expectsJson()
                ? response()->json(['error' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        $user = $request->user()->fresh();

        if ($request->expectsJson()) {
            return response()->json([
                'payouts_enabled'   => $user->connect_payouts_enabled,
                'details_submitted' => $user->connect_details_submitted,
                'requirements'      => $user->connectOutstandingRequirements(),
            ]);
        }

        return $user->connect_payouts_enabled
            ? back()->with('status', 'Your payout account is ready. Commissions will be paid straight to your bank.')
            : back()->with('info', 'Stripe still needs a few details before payouts can be switched on.');
    }

    /** Stripe-hosted onboarding, the fallback path. */
    public function hosted(Request $request)
    {
        try {
            $url = $this->connect->createAccountLink(
                $request->user(),
                returnUrl: route('member.payouts.index'),
                refreshUrl: route('member.payouts.hosted'),
            );
        } catch (BillingException $e) {
            return redirect()->route('member.payouts.index')->with('error', $e->getMessage());
        } catch (ApiErrorException $e) {
            Log::error('Account link create failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return redirect()->route('member.payouts.index')->with('error', 'We could not open Stripe. Please try again.');
        }

        return redirect()->away($url);
    }
}
