<?php

namespace App\Services\Stripe;

use App\Exceptions\BillingException;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Subscription as SubscriptionModel;
use App\Models\User;
use App\Support\Prelaunch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\SetupIntent;
use Stripe\Subscription as StripeSubscription;

/**
 * Partner subscriptions: customers, saved cards, and the trial subscription.
 *
 * ── Trial model ───────────────────────────────────────────────────────────────
 * Pre-launch signups are promised they will not be charged until launch. The
 * launch date is not known when they sign up, so their subscription parks on a
 * placeholder trial far in the future and is flagged `is_prelaunch_trial`. When
 * the date is finally set, `billing:apply-prelaunch-end` rolls every flagged
 * subscription's trial end onto the real schedule and clears the flag. Anyone
 * signing up after launch simply gets the standard trial.
 *
 * The alternative — creating subscriptions retroactively for everyone on launch
 * morning — means thousands of provider calls in one batch, on the day with the
 * least slack to absorb a failure.
 *
 * The provider holds the truth throughout. The local rows are a mirror kept in
 * step by this service and the webhook processor.
 */
class BillingService
{
    /** Stripe rejects a subscription whose trial ends inside 48 hours. */
    private const MIN_TRIAL_HOURS = 49;

    public function __construct(private readonly StripeClientFactory $stripe) {}

    // ── Customers ─────────────────────────────────────────────────────────────

    public function customerFor(User $user): string
    {
        if ($user->provider_customer_id) {
            return $user->provider_customer_id;
        }

        $params = [
            'email'    => $user->billing_email ?: $user->email,
            'name'     => $user->name,
            'metadata' => [
                'user_id'       => (string) $user->id,
                'referral_code' => (string) $user->referral_code,
                'app'           => 'quantumlife',
            ],
        ];

        $customer = $this->stripe->client()->customers->create($params, [
            'idempotency_key' => $this->stripe->idempotencyKey('customer_user_' . $user->id, $params),
        ]);

        // Assigned directly, not mass-assigned — see the note on User.
        $user->provider_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    // ── Card capture ──────────────────────────────────────────────────────────

    /**
     * A SetupIntent for the browser's payment element to confirm against.
     *
     * `off_session` usage is what permits charging the card unattended when the
     * trial eventually converts, possibly a year later. Raw card details go
     * from the browser to the provider and never touch this server.
     */
    public function createSetupIntent(User $user): SetupIntent
    {
        return $this->stripe->client()->setupIntents->create([
            'customer'             => $this->customerFor($user),
            'usage'                => 'off_session',
            'payment_method_types' => ['card'],
            'metadata'             => ['user_id' => (string) $user->id],
        ]);
    }

    /**
     * Attach a confirmed payment method to the partner and record it locally.
     *
     * One physical card, one account. The application check produces a friendly
     * error; the unique index on `unique_fingerprint` is what actually holds
     * under a race.
     */
    public function attachPaymentMethod(User $user, string $paymentMethodId, bool $makeDefault = true): PaymentMethod
    {
        $customerId = $this->customerFor($user);
        $client     = $this->stripe->client();

        $pm = $client->paymentMethods->retrieve($paymentMethodId);

        // A SetupIntent confirmed against our customer is already attached.
        if ($pm->customer !== $customerId) {
            if ($pm->customer !== null) {
                // Attached to someone else's customer — refuse rather than steal it.
                throw BillingException::duplicateCard();
            }

            $pm = $client->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        }

        $card        = $pm->card ?? null;
        $fingerprint = $card->fingerprint ?? null;
        $enforce     = (bool) config('stripe.safeguards.enforce_card_uniqueness');

        if ($enforce && $fingerprint) {
            // Queries `fingerprint`, which is always populated, rather than
            // `unique_fingerprint`, which is only set while enforcement is on —
            // so switching enforcement on immediately covers cards captured
            // while it was off.
            $takenByAnother = PaymentMethod::where('fingerprint', $fingerprint)
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($takenByAnother) {
                $client->paymentMethods->detach($paymentMethodId);
                throw BillingException::duplicateCard();
            }
        }

        try {
            $record = DB::transaction(function () use ($user, $pm, $card, $fingerprint, $enforce, $makeDefault) {
                if ($makeDefault) {
                    $user->paymentMethods()->update(['is_default' => false]);
                }

                return PaymentMethod::updateOrCreate(
                    ['provider_payment_method_id' => $pm->id],
                    [
                        'user_id'            => $user->id,
                        'fingerprint'        => $fingerprint,
                        'unique_fingerprint' => $enforce ? $fingerprint : null,
                        'brand'              => $card->brand ?? null,
                        'last4'              => $card->last4 ?? null,
                        'exp_month'          => $card->exp_month ?? null,
                        'exp_year'           => $card->exp_year ?? null,
                        'country'            => $card->country ?? null,
                        'funding'            => $card->funding ?? null,
                        'is_default'         => $makeDefault,
                    ],
                );
            });
        } catch (QueryException) {
            // Lost the race on the unique index.
            $client->paymentMethods->detach($paymentMethodId);
            throw BillingException::duplicateCard();
        }

        if ($makeDefault) {
            $client->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);
        }

        return $record;
    }

    public function detachPaymentMethod(User $user, PaymentMethod $method): void
    {
        // Removing the only card on a live subscription must be refused here,
        // not allowed and then silently discovered at renewal.
        $isLast = $user->paymentMethods()->count() === 1;

        if ($isLast && $user->activeSubscription() !== null) {
            throw BillingException::lastCardOnActiveSubscription();
        }

        try {
            $this->stripe->client()->paymentMethods->detach($method->provider_payment_method_id);
        } catch (ApiErrorException $e) {
            // Already gone at the provider — drop the local row regardless.
            Log::info('Detach payment method no-op', [
                'payment_method' => $method->provider_payment_method_id,
                'error'          => $e->getMessage(),
            ]);
        }

        $wasDefault = $method->is_default;
        $method->delete();

        if ($wasDefault) {
            $user->paymentMethods()->oldest('id')->first()?->update(['is_default' => true]);
        }
    }

    // ── Trial arithmetic ──────────────────────────────────────────────────────

    /**
     * When the first charge should land, and whether this is a pre-launch trial.
     *
     * @return array{0: Carbon, 1: bool}
     */
    public function resolveTrialEnd(?Carbon $now = null): array
    {
        $now       = $now ?? now();
        $trialDays = (int) config('stripe.subscription.trial_days', 30);
        $launchAt  = Prelaunch::endsAt();

        if ($launchAt === null) {
            // Pre-launch is running and no date has been published. Park it.
            $days = (int) config('stripe.subscription.prelaunch_placeholder_days', 365);

            return [$now->copy()->addDays($days), true];
        }

        if ($launchAt->isAfter($now)) {
            // Launch date known: free until then, plus the standard trial.
            return [$launchAt->copy()->addDays($trialDays), true];
        }

        return [$now->copy()->addDays($trialDays), false];
    }

    // ── Subscription lifecycle ────────────────────────────────────────────────

    /**
     * Start a subscription: attach the card, then open the trial.
     *
     * Safe to call twice. The browser can retry after a slow confirm, and a
     * duplicate call returns the existing subscription rather than opening a
     * second one.
     */
    public function startSubscription(User $user, string $paymentMethodId): SubscriptionModel
    {
        $priceId = config('stripe.subscription.price_id');

        if (blank($priceId)) {
            throw BillingException::noPriceConfigured();
        }

        $paymentMethod = $this->attachPaymentMethod($user, $paymentMethodId);

        // A real lock, held across the provider call.
        //
        // Checking for an existing subscription inside a database transaction
        // would not do: the row lock releases when that transaction commits,
        // which is before the provider has been called at all — so two
        // concurrent submits could both see "none" and both create one. The
        // idempotency key is no substitute either, because its parameters
        // include a trial end computed from the current time, so two requests a
        // second apart hash differently.
        try {
            return Cache::lock("subscription-start-user-{$user->id}", 60)->block(20, function () use ($user, $paymentMethod, $priceId) {
                if ($existing = $user->subscriptions()->entitling()->first()) {
                    return $existing;
                }

                return $this->createTrialSubscription($user, $paymentMethod, $priceId);
            });
        } catch (LockTimeoutException) {
            throw new BillingException(
                'Your membership is still being set up. Give it a moment and refresh — no charge has been made.'
            );
        }
    }

    /** Only ever called under the lock taken in startSubscription(). */
    private function createTrialSubscription(User $user, PaymentMethod $paymentMethod, string $priceId): SubscriptionModel
    {
        [$trialEnd, $isPrelaunch] = $this->resolveTrialEnd();

        $floor = now()->addHours(self::MIN_TRIAL_HOURS);

        if ($trialEnd->isBefore($floor)) {
            $trialEnd = $floor;
        }

        $params = [
            'customer'               => $this->customerFor($user),
            'items'                  => [['price' => $priceId]],
            'trial_end'              => $trialEnd->getTimestamp(),
            'default_payment_method' => $paymentMethod->provider_payment_method_id,

            // If the card is gone by the time the trial ends, cancel rather than
            // leave an unpaid subscription hanging around.
            'trial_settings' => [
                'end_behavior' => ['missing_payment_method' => 'cancel'],
            ],

            'metadata' => [
                'user_id'            => (string) $user->id,
                'is_prelaunch_trial' => $isPrelaunch ? 'true' : 'false',
                'app'                => 'quantumlife',
            ],
        ];

        try {
            $subscription = $this->stripe->client()->subscriptions->create($params, [
                'idempotency_key' => $this->stripe->idempotencyKey('subscription_start_user_' . $user->id, $params),
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Subscription create failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            throw new BillingException(
                'We could not start your membership: ' . ($e->getError()->message ?? 'payment provider error')
                . ' No charge has been made.'
            );
        }

        return $this->syncSubscription($user, $subscription, $isPrelaunch);
    }

    /**
     * Write a provider subscription into the mirror and re-derive the role.
     *
     * @param bool|null $isPrelaunch Force the flag on creation; null keeps what
     *                               is already recorded.
     */
    public function syncSubscription(User $user, StripeSubscription $subscription, ?bool $isPrelaunch = null): SubscriptionModel
    {
        $item = $subscription->items->data[0] ?? null;

        // From the Basil API versions the period lives on the subscription item
        // rather than the subscription. Read both, so a version bump cannot
        // blank the dates.
        $periodStart = $subscription->current_period_start ?? $item?->current_period_start;
        $periodEnd   = $subscription->current_period_end   ?? $item?->current_period_end;

        // Nulls are dropped so a partial provider payload — a webhook that omits
        // a field — cannot blank a column whose value is already known. Booleans
        // survive the filter, so cancel_at_period_end can still go false.
        $attributes = array_filter([
            'user_id'                   => $user->id,
            'provider'                  => 'stripe',
            'provider_price_id'         => $item?->price?->id,
            'status'                    => $subscription->status,
            'trial_ends_at'             => $subscription->trial_end ? Carbon::createFromTimestamp($subscription->trial_end) : null,
            'is_prelaunch_trial'        => $isPrelaunch,
            'current_period_start'      => $periodStart ? Carbon::createFromTimestamp($periodStart) : null,
            'current_period_end'        => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'cancel_at_period_end'      => $subscription->cancel_at_period_end,
            'canceled_at'               => $subscription->canceled_at ? Carbon::createFromTimestamp($subscription->canceled_at) : null,
            'ended_at'                  => $subscription->ended_at ? Carbon::createFromTimestamp($subscription->ended_at) : null,
            'default_payment_method_id' => is_string($subscription->default_payment_method)
                ? $subscription->default_payment_method
                : ($subscription->default_payment_method->id ?? null),
            'latest_invoice_id'         => is_string($subscription->latest_invoice)
                ? $subscription->latest_invoice
                : ($subscription->latest_invoice->id ?? null),
            'amount'                    => $item?->price?->unit_amount,
            'currency'                  => $item?->price?->currency ?? config('stripe.subscription.currency'),
        ], fn ($v) => $v !== null);

        $attributes['last_synced_at'] = now();

        $record = SubscriptionModel::updateOrCreate(
            ['provider_subscription_id' => $subscription->id],
            $attributes,
        );

        $user->refresh();
        $this->syncRole($user);

        return $record;
    }

    /** Re-read from the provider and repair the mirror. */
    public function refresh(SubscriptionModel $subscription): SubscriptionModel
    {
        $remote = $this->stripe->client()->subscriptions->retrieve(
            $subscription->provider_subscription_id,
        );

        return $this->syncSubscription($subscription->user, $remote);
    }

    /**
     * Move a subscription's trial end.
     *
     * Used when the launch date is finally decided, and by admin extensions.
     */
    public function setTrialEnd(SubscriptionModel $subscription, Carbon $trialEnd, bool $stillPrelaunch = false): SubscriptionModel
    {
        $floor = now()->addHours(self::MIN_TRIAL_HOURS);

        if ($trialEnd->isBefore($floor)) {
            $trialEnd = $floor;
        }

        $remote = $this->stripe->client()->subscriptions->update(
            $subscription->provider_subscription_id,
            [
                'trial_end' => $trialEnd->getTimestamp(),
                // The partner agreed to a price at signup. Moving the trial date
                // is not a plan change and must not generate a proration invoice.
                'proration_behavior' => 'none',
                'metadata'           => ['is_prelaunch_trial' => $stillPrelaunch ? 'true' : 'false'],
            ],
        );

        return $this->syncSubscription($subscription->user, $remote, $stillPrelaunch);
    }

    public function cancelAtPeriodEnd(SubscriptionModel $subscription): SubscriptionModel
    {
        $remote = $this->stripe->client()->subscriptions->update(
            $subscription->provider_subscription_id,
            ['cancel_at_period_end' => true],
        );

        return $this->syncSubscription($subscription->user, $remote);
    }

    public function resume(SubscriptionModel $subscription): SubscriptionModel
    {
        // A subscription past ended_at cannot be resumed; the flow has to create
        // a new one. Without this check "Resume" fails silently for exactly the
        // lapsed accounts most likely to press it.
        if ($subscription->hasEnded()) {
            throw BillingException::cannotResumeEnded();
        }

        $remote = $this->stripe->client()->subscriptions->update(
            $subscription->provider_subscription_id,
            ['cancel_at_period_end' => false],
        );

        return $this->syncSubscription($subscription->user, $remote);
    }

    // ── Role entitlement ──────────────────────────────────────────────────────

    /**
     * Keep the partner's role in step with whether they hold a live subscription.
     *
     * Admin roles are never touched: an admin's access does not come from a
     * card, and demoting one because a subscription lapsed would lock staff out
     * of the panel.
     */
    public function syncRole(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $target = $user->hasActiveMembership() ? Role::PAID_MEMBER : Role::FREE_MEMBER;

        if ($user->hasRole($target)) {
            return;
        }

        $role = Role::findByName($target);

        if ($role === null) {
            Log::warning('Cannot sync membership role: role missing', ['role' => $target]);

            return;
        }

        $user->role_id = $role->id;
        $user->save();
    }

    // ── Read helpers ──────────────────────────────────────────────────────────

    /** Recent invoices for the billing screen. Never throws — returns []. */
    public function invoicesFor(User $user, int $limit = 12): array
    {
        if (! $user->provider_customer_id) {
            return [];
        }

        try {
            $invoices = $this->stripe->client()->invoices->all([
                'customer' => $user->provider_customer_id,
                'limit'    => $limit,
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('Could not list invoices', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return [];
        }

        return collect($invoices->data)->map(fn ($i) => [
            'id'         => $i->id,
            'number'     => $i->number,
            'status'     => $i->status,
            'total'      => $i->total,
            'currency'   => $i->currency,
            'created'    => Carbon::createFromTimestamp($i->created),
            'pdf'        => $i->invoice_pdf,
            'hosted_url' => $i->hosted_invoice_url,
        ])->all();
    }
}
