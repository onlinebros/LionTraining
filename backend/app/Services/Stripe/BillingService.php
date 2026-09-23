<?php

namespace App\Services\Stripe;

use App\Exceptions\BillingException;
use App\Models\CardFingerprint;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Subscription as SubscriptionModel;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
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
 * ── Enrollment options ────────────────────────────────────────────────────────
 * Every partner saves a card at sign-up and picks when billing starts. There is
 * no free trial; a Stripe trial is only how the card is held until then.
 *
 * `launch` ("Recover my genius now"): the first charge lands the day the
 * training program opens. That date is not known when most partners sign up, so
 * their subscription parks on a placeholder trial and is flagged
 * `is_prelaunch_trial`. Once the date is set, `billing:apply-prelaunch-end`
 * moves every flagged trial to end on it and clears the flag. After launch this
 * option charges at sign-up.
 *
 * `commission` ("Wait for my commissions"): parked until the partner's paid
 * commissions reach `commission_threshold`, then moved onto the launch schedule
 * above (see CommissionBillingTrigger). Stripe caps how far out a trial can sit,
 * so `billing:commission-holds` keeps pushing the hold back out.
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
     * One physical card, one account, for good. The first account to save a card
     * claims its fingerprint in `card_fingerprints`, and the claim is never
     * released: not when that account removes the card, and not when the account
     * is deleted. The check below gives a friendly error; the ledger's unique
     * index is what holds under a race.
     *
     * Apple Pay and Google Pay are refused while enforcement is on. A wallet hands
     * over a device-specific card number, so one physical card fingerprints
     * differently in a wallet than typed in, and could back two accounts.
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

        if ($enforce) {
            $refusal = null;

            if ($pm->type !== 'card') {
                // Link and bank payment methods carry no card fingerprint to check.
                $refusal = BillingException::cardRequired();
            } elseif (($card->wallet ?? null) !== null) {
                $refusal = BillingException::walletNotAccepted();
            } elseif (blank($fingerprint)) {
                $refusal = BillingException::cardNotVerifiable();
            } elseif (CardFingerprint::isHeldByAnother($fingerprint, $user)) {
                $refusal = BillingException::duplicateCard();
            }

            if ($refusal !== null) {
                // Leave nothing on this customer that could be charged later.
                $client->paymentMethods->detach($paymentMethodId);
                throw $refusal;
            }
        }

        try {
            $record = DB::transaction(function () use ($user, $pm, $card, $fingerprint, $enforce, $makeDefault) {
                // Same transaction as the card row: a card is never saved without
                // its claim, or claimed without being saved.
                if ($enforce && ! CardFingerprint::claim($fingerprint, $user)) {
                    throw BillingException::duplicateCard();
                }

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
        } catch (QueryException|BillingException) {
            // Lost the race for the card: the ledger claim, or the unique index
            // on payment_methods.
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
     * When the first charge of a `launch` subscription should land, and whether
     * it is still parked for launch.
     *
     * A null date means the training program is already open: charge now.
     *
     * @return array{0: Carbon|null, 1: bool}
     */
    public function resolveTrialEnd(?Carbon $now = null): array
    {
        $now      = $now ?? now();
        $launchAt = Prelaunch::endsAt();

        if ($launchAt === null) {
            // No date has been published. Park it.
            $days = (int) config('stripe.subscription.prelaunch_placeholder_days', 365);

            return [$now->copy()->addDays($days), true];
        }

        if ($launchAt->isAfter($now)) {
            // Date known: the first charge is launch day itself. Still flagged,
            // so apply-prelaunch-end follows the date if it slips.
            return [$launchAt->copy(), true];
        }

        return [null, false];
    }

    /** Where a commission hold is parked from now. */
    public function commissionHoldEnd(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->addDays((int) config('stripe.subscription.commission_hold_days', 700));
    }

    // ── Subscription lifecycle ────────────────────────────────────────────────

    /**
     * Start a subscription: attach the card, then open it under the chosen
     * enrollment option.
     *
     * Safe to call twice. The browser can retry after a slow confirm, and a
     * duplicate call returns the existing subscription rather than opening a
     * second one.
     */
    public function startSubscription(
        User $user,
        string $paymentMethodId,
        string $trigger = SubscriptionModel::TRIGGER_LAUNCH,
    ): SubscriptionModel {
        $priceId = config('stripe.subscription.price_id');

        if (blank($priceId)) {
            throw BillingException::noPriceConfigured();
        }

        if (! in_array($trigger, SubscriptionModel::TRIGGERS, true)) {
            throw new BillingException('Choose an enrollment option.');
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
            return Cache::lock("subscription-start-user-{$user->id}", 60)->block(20, function () use ($user, $paymentMethod, $priceId, $trigger) {
                if ($existing = $user->subscriptions()->entitling()->first()) {
                    return $existing;
                }

                $subscription = $this->createSubscription($user, $paymentMethod, $priceId, $trigger);

                /*
                | Paying for the membership is what joining the membership
                | business line means, so record it.
                |
                | This is the door out of the card-free B2B side: a PlasmaGuard
                | partner who decides they want the training program comes
                | through here, and from this moment the training routes open
                | and the ordinary subscription gate applies to them. For
                | everybody else it is a no-op — they already hold it, and
                | associateOpportunity() is idempotent.
                |
                | Not made primary. Which door they came in by is a historical
                | fact about them and stays true.
                */
                $user->associateOpportunity(
                    Opportunity::defaultKey(),
                    UserOpportunity::SOURCE_SELF,
                );

                return $subscription;
            });
        } catch (LockTimeoutException) {
            throw new BillingException(
                'Your membership is still being set up. Give it a moment and refresh — no charge has been made.'
            );
        }
    }

    /** Only ever called under the lock taken in startSubscription(). */
    private function createSubscription(User $user, PaymentMethod $paymentMethod, string $priceId, string $trigger): SubscriptionModel
    {
        if ($trigger === SubscriptionModel::TRIGGER_COMMISSION) {
            [$trialEnd, $isPrelaunch] = [$this->commissionHoldEnd(), false];
        } else {
            [$trialEnd, $isPrelaunch] = $this->resolveTrialEnd();
        }

        $params = [
            'customer'               => $this->customerFor($user),
            'items'                  => [['price' => $priceId]],
            'default_payment_method' => $paymentMethod->provider_payment_method_id,

            'metadata' => [
                'user_id'            => (string) $user->id,
                'is_prelaunch_trial' => $isPrelaunch ? 'true' : 'false',
                'billing_trigger'    => $trigger,
                'app'                => 'quantumlife',
            ],
        ];

        if ($trialEnd === null) {
            // The training program is open: charge the saved card now. If that
            // payment fails, Stripe creates nothing, so a retry cannot leave an
            // unpaid subscription behind.
            $params['payment_behavior'] = 'error_if_incomplete';
        } else {
            $floor = now()->addHours(self::MIN_TRIAL_HOURS);

            if ($trialEnd->isBefore($floor)) {
                $trialEnd = $floor;
            }

            $params['trial_end'] = $trialEnd->getTimestamp();

            // If the card is gone by the time the hold ends, cancel rather than
            // leave an unpaid subscription hanging around.
            $params['trial_settings'] = [
                'end_behavior' => ['missing_payment_method' => 'cancel'],
            ];
        }

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
     * Put a subscription on the launch schedule: parked until the training
     * program opens, or charged now if it already has.
     *
     * Used when a commission hold reaches its threshold, when a held partner
     * chooses to start now, and by admins.
     */
    public function startBillingOnLaunchSchedule(SubscriptionModel $subscription): SubscriptionModel
    {
        // Already paying (or finished): setting a trial end here would hand a
        // paying partner free time.
        if ($subscription->status !== SubscriptionModel::STATUS_TRIALING) {
            return $subscription;
        }

        [$trialEnd, $isPrelaunch] = $this->resolveTrialEnd();

        if ($trialEnd === null) {
            return $this->endTrialNow($subscription);
        }

        if (Prelaunch::endsAt() === null && $subscription->trial_ends_at !== null) {
            // No date yet. Leave the hold where it is; apply-prelaunch-end moves
            // it once the date is set.
            $trialEnd = $subscription->trial_ends_at->copy();
        }

        return $this->setTrialEnd($subscription, $trialEnd, $isPrelaunch, SubscriptionModel::TRIGGER_LAUNCH);
    }

    /** End the trial now; Stripe invoices and charges the saved card immediately. */
    public function endTrialNow(SubscriptionModel $subscription): SubscriptionModel
    {
        $remote = $this->stripe->client()->subscriptions->update(
            $subscription->provider_subscription_id,
            [
                'trial_end'          => 'now',
                'proration_behavior' => 'none',
                'metadata'           => [
                    'is_prelaunch_trial' => 'false',
                    'billing_trigger'    => SubscriptionModel::TRIGGER_LAUNCH,
                ],
            ],
        );

        return $this->syncSubscription($subscription->user, $remote, false);
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
            // Stripe's metadata is the record of the enrollment option, so a
            // webhook that arrives after a change cannot put it back.
            'billing_trigger'           => in_array($subscription->metadata['billing_trigger'] ?? null, SubscriptionModel::TRIGGERS, true)
                ? $subscription->metadata['billing_trigger']
                : null,
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
     * Used when the launch date is finally decided, by admin extensions, and to
     * renew commission holds. Leaves the enrollment option alone unless a new
     * one is given.
     */
    public function setTrialEnd(
        SubscriptionModel $subscription,
        Carbon $trialEnd,
        bool $stillPrelaunch = false,
        ?string $trigger = null,
    ): SubscriptionModel {
        $floor = now()->addHours(self::MIN_TRIAL_HOURS);

        if ($trialEnd->isBefore($floor)) {
            $trialEnd = $floor;
        }

        $metadata = ['is_prelaunch_trial' => $stillPrelaunch ? 'true' : 'false'];

        if ($trigger !== null) {
            $metadata['billing_trigger'] = $trigger;
        }

        $remote = $this->stripe->client()->subscriptions->update(
            $subscription->provider_subscription_id,
            [
                'trial_end' => $trialEnd->getTimestamp(),
                // The partner agreed to a price at signup. Moving the trial date
                // is not a plan change and must not generate a proration invoice.
                'proration_behavior' => 'none',
                'metadata'           => $metadata,
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
