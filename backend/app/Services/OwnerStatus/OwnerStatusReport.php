<?php

namespace App\Services\OwnerStatus;

use App\Models\ChangeRequest;
use App\Models\CommissionLedger;
use App\Models\CommissionPayout;
use App\Models\ErrorLog;
use App\Models\PartnerWebhookDelivery;
use App\Models\ProductPartnerPayment;
use App\Models\SiteSetting;
use App\Models\Sponsorship;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VendorLead;
use App\Models\VendorPaymentAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the owner's assistant reads through the owner-status API
 * (memory-bank/owner-status-api.md). Read-only: every method only queries.
 *
 * Rules held throughout:
 *  - Money is integer cents in fields named *_cents. The commission tables
 *    hold decimal dollars and are converted here; everything else is already
 *    minor units.
 *  - Vendor gross (collected by the vendor) is never added to company money.
 *  - No emails, phones, addresses, Stripe ids, request bodies, context JSON or
 *    stack traces. Free text is truncated and has email addresses masked.
 *  - Optional tables are checked before they are read, so an empty or partly
 *    migrated database still answers.
 */
class OwnerStatusReport
{
    private const TEXT_LIMIT = 200;

    // ── Sections ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $sales = $this->sales(30);
        $tickets = $this->tickets();
        $errors = $this->errors(7);
        $approvals = $this->approvals();

        return [
            'generated_at' => $this->now(),
            'app' => $this->appName(),
            'sales_30d' => [
                'vendor_converted_count' => array_sum(array_column($sales['vendor_sales']['vendors'], 'converted_count')),
                'vendor_our_share_cents' => array_sum(array_column($sales['vendor_sales']['vendors'], 'our_share_cents')),
                'vendor_awaiting_purchase_count' => array_sum(array_column($sales['vendor_sales']['vendors'], 'awaiting_purchase_count')),
                'vendor_our_share_uninvoiced_cents' => array_sum(array_column($sales['vendor_sales']['vendors'], 'our_share_uninvoiced_cents')),
                'subscriptions_entitling_count' => $sales['subscriptions']['entitling_count'],
                'subscriptions_new_count' => $sales['subscriptions']['new_in_window_count'],
                'subscriptions_canceled_count' => $sales['subscriptions']['canceled_in_window_count'],
                'activated_signups_count' => $sales['signups']['activated_count'],
            ],
            'tickets' => [
                'open_count' => $tickets['support']['by_status']['open'] ?? 0,
                'in_progress_count' => $tickets['support']['by_status']['in_progress'] ?? 0,
                'change_requests_awaiting_review_count' => ($tickets['change_requests']['by_status']['submitted'] ?? 0)
                    + ($tickets['change_requests']['by_status']['needs_info'] ?? 0),
            ],
            'errors_7d' => [
                'error_log_count' => $errors['error_logs']['count'],
                'failed_jobs_count' => $errors['failed_jobs']['count'],
                'stripe_webhook_problem_count' => $errors['stripe_webhooks']['count'],
                'partner_webhook_failed_count' => $errors['partner_webhooks']['count'],
                'vendor_payments_needing_attention_count' => $errors['vendor_payment_audits']['count'],
                'subscriptions_past_due_or_unpaid_count' => $errors['subscriptions_past_due_or_unpaid_count'],
                'payout_transfers_failed_count' => $errors['payout_transfers_failed']['count'],
            ],
            'approvals' => [
                'sponsorships_pending_count' => $approvals['sponsorships_pending_count'],
                'commission_ledger_pending_count' => $approvals['commission_ledger_pending']['count'],
                'commission_payouts_pending_count' => $approvals['commission_payouts']['pending']['count'],
                'commission_payouts_approved_unpaid_count' => $approvals['commission_payouts']['approved_unpaid']['count'],
                'product_partner_payments_pending_count' => $approvals['product_partner_payments_pending']['count'],
                'vendor_leads_attribution_review_count' => $approvals['vendor_leads_attribution_review_count'],
                'vendor_leads_address_review_count' => $approvals['vendor_leads_address_review_count'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sales(int $days, int $limit = 100): array
    {
        $since = now()->subDays($days);

        return [
            'generated_at' => $this->now(),
            'days' => $days,
            'since' => $since->toIso8601String(),
            'vendor_sales' => $this->vendorSales($since, $limit),
            'subscriptions' => $this->subscriptions($since),
            'signups' => $this->signups($since),
        ];
    }

    /** @return array<string, mixed> */
    public function tickets(): array
    {
        $open = ['open', 'in_progress'];

        $tickets = $this->hasTable('support_tickets') ? SupportTicket::query()
            ->whereIn('status', $open)
            ->with(['user:id,name', 'assignee:id,name'])
            ->withMax('replies as last_reply_at', 'created_at')
            ->latest()
            ->limit(50)
            ->get() : collect();

        $requests = $this->hasTable('change_requests') ? ChangeRequest::query()
            ->whereIn('status', ['submitted', 'needs_info'])
            ->with('user:id,name')
            ->orderByDesc('submitted_at')
            ->limit(20)
            ->get() : collect();

        return [
            'generated_at' => $this->now(),
            'support' => [
                'by_status' => $this->countBy('support_tickets', 'status'),
                'open_by_priority' => $this->countBy('support_tickets', 'priority', fn ($q) => $q->whereIn('status', $open)),
                'open' => $tickets->map(fn (SupportTicket $t) => [
                    'id' => $t->id,
                    'ticket_number' => $t->ticket_number,
                    'subject' => $this->clean($t->subject),
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'category' => $t->category,
                    'channel' => $t->channel,
                    'created_at' => $this->iso($t->created_at),
                    'last_reply_at' => $this->iso($t->last_reply_at),
                    'assigned_to' => $t->assignee?->name,
                    'requester' => $this->clean($t->requesterDisplayName(), 120),
                ])->values()->all(),
            ],
            'change_requests' => [
                'by_status' => $this->countBy('change_requests', 'status'),
                'awaiting_review' => $requests->map(fn (ChangeRequest $r) => [
                    'id' => $r->id,
                    'title' => $this->clean($r->title),
                    'status' => $r->status,
                    'submitted_at' => $this->iso($r->submitted_at),
                    'author' => $r->user?->name,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * One support ticket and its whole conversation, for the owner's assistant to draft a reply.
     * Internal staff notes are included and marked. Email addresses in text are masked.
     *
     * @return array<string, mixed>|null
     */
    public function ticket(int $id): ?array
    {
        $ticket = $this->hasTable('support_tickets')
            ? SupportTicket::with(['user:id,name', 'assignee:id,name', 'replies' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'replies.user:id,name'])->find($id)
            : null;
        if (! $ticket) {
            return null;
        }

        return [
            'generated_at' => $this->now(),
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $this->clean($ticket->subject),
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'channel' => $ticket->channel,
                'created_at' => $this->iso($ticket->created_at),
                'closed_at' => $this->iso($ticket->closed_at),
                'assigned_to' => $ticket->assignee?->name,
                'requester' => $this->clean($ticket->requesterDisplayName(), 120),
                'requester_has_account' => $ticket->user_id !== null,
            ],
            'messages' => $ticket->replies->map(fn ($r) => [
                'at' => $this->iso($r->created_at),
                'author' => $r->user?->name ?? $this->clean($ticket->requesterDisplayName(), 120),
                'from_requester' => $r->user_id === null || $r->user_id === $ticket->user_id,
                'internal_note' => (bool) $r->is_internal,
                'body' => $this->clean(trim(html_entity_decode(strip_tags((string) $r->body))), 4000),
            ])->values()->all(),
            'notes' => [
                'messages' => 'Oldest first. internal_note = staff-only note the requester never sees. Text is truncated to 4000 characters and email addresses are masked.',
                'requester_has_account' => 'false = the ticket came from the website form: panel replies never reach that person; email them instead.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function errors(int $days): array
    {
        $since = now()->subDays($days);

        return [
            'generated_at' => $this->now(),
            'days' => $days,
            'since' => $since->toIso8601String(),
            'error_logs' => $this->errorLogs($since),
            'failed_jobs' => $this->failedJobs($since),
            'stripe_webhooks' => $this->stripeWebhookProblems($since),
            'partner_webhooks' => $this->partnerWebhookFailures($since),
            'vendor_payment_audits' => $this->vendorPaymentsNeedingAttention(),
            'subscriptions_past_due_or_unpaid_count' => $this->hasTable('subscriptions')
                ? Subscription::whereIn('status', [Subscription::STATUS_PAST_DUE, Subscription::STATUS_UNPAID])->count()
                : 0,
            'payout_transfers_failed' => $this->failedPayoutTransfers(),
        ];
    }

    /** @return array<string, mixed> */
    public function approvals(): array
    {
        $ledger = ['count' => 0, 'credits_cents' => 0, 'debits_cents' => 0];
        if ($this->hasTable('commission_ledger')) {
            $ledger = [
                'count' => CommissionLedger::pending()->count(),
                'credits_cents' => $this->dollarsToCents(CommissionLedger::pending()->credits()->sum('amount')),
                'debits_cents' => $this->dollarsToCents(CommissionLedger::pending()->debits()->sum('amount')),
            ];
        }

        $payouts = fn (string $status) => $this->hasTable('commission_payouts') ? [
            'count' => CommissionPayout::where('status', $status)->count(),
            'amount_cents' => $this->dollarsToCents(CommissionPayout::where('status', $status)->sum('total_amount')),
        ] : ['count' => 0, 'amount_cents' => 0];

        $partnerPayments = ['count' => 0, 'by_currency' => []];
        if ($this->hasTable('product_partner_payments')) {
            $rows = ProductPartnerPayment::pending()
                ->selectRaw('currency, count(*) as n, coalesce(sum(amount), 0) as total')
                ->groupBy('currency')->orderBy('currency')->toBase()->get();
            $partnerPayments = [
                'count' => (int) $rows->sum('n'),
                'by_currency' => $rows->map(fn ($r) => [
                    'currency' => $r->currency,
                    'count' => (int) $r->n,
                    'amount_cents' => (int) $r->total,
                ])->values()->all(),
            ];
        }

        $leads = $this->hasTable('vendor_leads');

        return [
            'generated_at' => $this->now(),
            'sponsorships_pending_count' => $this->hasTable('sponsorships') ? Sponsorship::where('status', 'pending')->count() : 0,
            'commission_ledger_pending' => $ledger,
            'commission_payouts' => [
                'pending' => $payouts('pending'),
                'approved_unpaid' => $payouts('approved'),
            ],
            'product_partner_payments_pending' => $partnerPayments,
            'vendor_leads_attribution_review_count' => $leads ? VendorLead::needsAttributionReview()->count() : 0,
            'vendor_leads_address_review_count' => $leads ? VendorLead::addressAwaitingReview()->count() : 0,
            'notes' => [
                'commission_ledger_pending' => 'Pending entries; credits and debits are summed separately.',
                'product_partner_payments_pending' => 'Payments a vendor says it made to us, waiting for our confirmation.',
            ],
        ];
    }

    // ── Sales parts ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function vendorSales(Carbon $since, int $limit = 100): array
    {
        if (! $this->hasTable('vendor_leads')) {
            return ['vendors' => [], 'sales' => [], 'notes' => $this->vendorNotes()];
        }

        $vendors = VendorLead::query()->distinct()->orderBy('vendor')->pluck('vendor');

        $rows = $vendors->map(function (string $vendor) use ($since) {
            $converted = fn () => VendorLead::forVendor($vendor)->converted();
            $inWindow = $converted()->where('converted_at', '>=', $since);
            $refunded = VendorLead::forVendor($vendor)->where('refunded_at', '>=', $since);

            // The same three buckets as Admin\VendorLeadController::reconciliation.
            $owed = $converted()->whereNull('invoiced_at')->where('our_share_amount', '>', 0);
            $invoiced = $converted()->whereNotNull('invoiced_at')->whereNull('settled_at');
            $settled = $converted()->whereNotNull('settled_at');

            return [
                'vendor' => $vendor,
                'currency' => $this->upper(VendorLead::forVendor($vendor)->whereNotNull('currency')->latest('id')->value('currency')),
                'converted_count' => (clone $inWindow)->count(),
                'vendor_gross_cents' => (int) (clone $inWindow)->sum('amount_total'),
                'our_share_cents' => (int) (clone $inWindow)->sum('our_share_amount'),
                'refunded_count' => (clone $refunded)->count(),
                'refunded_vendor_gross_cents' => (int) (clone $refunded)->sum('amount_total'),
                'awaiting_purchase_count' => VendorLead::forVendor($vendor)->awaitingPurchase()->count(),
                'our_share_uninvoiced_count' => (clone $owed)->count(),
                'our_share_uninvoiced_cents' => (int) (clone $owed)->sum('our_share_amount'),
                'our_share_invoiced_unsettled_count' => (clone $invoiced)->count(),
                'our_share_invoiced_unsettled_cents' => (int) (clone $invoiced)->sum('our_share_amount'),
                'our_share_settled_count' => (clone $settled)->count(),
                'our_share_settled_cents' => (int) (clone $settled)->sum('our_share_amount'),
            ];
        })->values()->all();

        // Every sale converted in the window (newest first, capped), with who it counts for and
        // who is paid: the credited partner, that partner's direct sponsor, and the earner.
        $sales = VendorLead::converted()
            ->where('converted_at', '>=', $since)
            ->with(['member:id,name', 'creditedMember:id,name,sponsor_id', 'creditedMember.sponsor:id,name', 'earner:id,name'])
            ->latest('converted_at')
            ->limit($limit)
            ->get()
            ->map(fn (VendorLead $l) => [
                'converted_at' => $this->iso($l->converted_at),
                'vendor' => $l->vendor,
                'product_key' => $l->product_key,
                'quantity' => (int) $l->quantity,
                'currency' => $this->upper($l->currency),
                'amount_total_cents' => (int) $l->amount_total,
                'our_share_cents' => (int) $l->our_share_amount,
                'attribution' => $l->attribution,
                'member' => $l->creditedMember?->name ?? $l->member?->name,
                'member_sponsor' => $l->creditedMember?->sponsor?->name,
                'earner' => $l->earner?->name,
                'refunded' => $l->refunded_at !== null,
            ])->values()->all();

        return ['vendors' => $rows, 'sales' => $sales, 'notes' => $this->vendorNotes()];
    }

    /** @return array<string, string> */
    private function vendorNotes(): array
    {
        return [
            'vendor_gross_cents' => 'Vendor gross, collected by the vendor. Not company revenue; do not add it to company figures.',
            'amount_total_cents' => 'Vendor gross, collected by the vendor. Not company revenue.',
            'our_share_cents' => 'The company revenue share of those sales, owed to us by the vendor.',
            'window' => 'Converted and refunded counts are for the window; awaiting purchase and the uninvoiced / invoiced / settled share totals are all-time state.',
            'sales' => 'Each sale converted in the window, newest first (capped by ?limit). member = the partner the sale is credited to; member_sponsor = that partner\'s direct sponsor; earner = who the commission is recorded for (on a partner\'s own purchase that is their sponsor). Commission amounts are not included.',
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptions(Carbon $since): array
    {
        if (! $this->hasTable('subscriptions')) {
            return [
                'by_status' => [], 'entitling_count' => 0, 'new_in_window_count' => 0,
                'canceled_in_window_count' => 0, 'past_due_or_unpaid_count' => 0,
                'active_amount_by_currency' => [], 'notes' => $this->subscriptionNotes(),
            ];
        }

        $amounts = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->selectRaw('currency, count(*) as n, coalesce(sum(amount), 0) as total')
            ->groupBy('currency')->orderBy('currency')->toBase()->get();

        return [
            'by_status' => $this->countBy('subscriptions', 'status'),
            'entitling_count' => Subscription::entitling()->count(),
            'new_in_window_count' => Subscription::where('created_at', '>=', $since)->count(),
            'canceled_in_window_count' => Subscription::where('canceled_at', '>=', $since)->count(),
            'past_due_or_unpaid_count' => Subscription::whereIn('status', [Subscription::STATUS_PAST_DUE, Subscription::STATUS_UNPAID])->count(),
            'active_amount_by_currency' => $amounts->map(fn ($r) => [
                'currency' => $this->upper($r->currency),
                'count' => (int) $r->n,
                'active_amount_cents' => (int) $r->total,
            ])->values()->all(),
            'notes' => $this->subscriptionNotes(),
        ];
    }

    /** @return array<string, string> */
    private function subscriptionNotes(): array
    {
        return [
            'active_amount_cents' => 'Sum of the per-period amount of active subscriptions. Not normalised MRR: periods are not converted to monthly.',
        ];
    }

    /** @return array<string, mixed> */
    private function signups(Carbon $since): array
    {
        // Activated accounts only. users holds ~1.3M rows, mostly imported unclaimed spots, and
        // created_at is not indexed: counting every row took ~25 s on production. The
        // account_status index keeps this in milliseconds, and real sign-ups are what matter.
        $activated = fn () => User::where('created_at', '>=', $since)->activated();

        $byLine = $activated()
            ->selectRaw('primary_opportunity, count(*) as n')
            ->groupBy('primary_opportunity')->orderBy('primary_opportunity')->toBase()->get()
            ->mapWithKeys(fn ($r) => [$r->primary_opportunity ?? 'default' => (int) $r->n]);

        return [
            'activated_count' => $activated()->count(),
            'by_opportunity' => $byLine->all(),
            'notes' => [
                'activated_count' => 'Accounts created in the window with account_status active. Imported unclaimed spots and merged accounts are not counted.',
                'by_opportunity' => 'Those activated accounts keyed by users.primary_opportunity; "default" is the default line.',
            ],
        ];
    }

    // ── Error parts ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function errorLogs(Carbon $since): array
    {
        if (! $this->hasTable('error_logs')) {
            return ['count' => 0, 'by_level' => [], 'top' => []];
        }

        $base = fn () => ErrorLog::unresolved()->where('created_at', '>=', $since);

        $top = $base()
            ->selectRaw('message, file, line, count(*) as n, max(created_at) as last_seen, max(url) as url')
            ->groupBy('message', 'file', 'line')
            ->orderByDesc('n')->orderByDesc('last_seen')
            ->limit(20)->toBase()->get();

        return [
            'count' => $base()->count(),
            'by_level' => $base()->selectRaw('level, count(*) as n')->groupBy('level')->toBase()->pluck('n', 'level')->map(fn ($n) => (int) $n)->all(),
            'top' => $top->map(fn ($r) => [
                'message' => $this->clean($r->message),
                'count' => (int) $r->n,
                'last_seen' => $this->iso($r->last_seen),
                'location' => $r->file ? $this->relativePath($r->file).($r->line ? ':'.$r->line : '') : null,
                'path' => $this->urlPath($r->url),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function failedJobs(Carbon $since): array
    {
        if (! $this->hasTable('failed_jobs')) {
            return ['count' => 0, 'recent' => []];
        }

        $base = fn () => DB::table('failed_jobs')->where('failed_at', '>=', $since);

        return [
            'count' => $base()->count(),
            'recent' => $base()->orderByDesc('failed_at')->limit(10)->get(['payload', 'queue', 'exception', 'failed_at'])
                ->map(fn ($r) => [
                    'job' => $this->clean(json_decode((string) $r->payload, true)['displayName'] ?? null, 120),
                    'queue' => $r->queue,
                    'failed_at' => $this->iso($r->failed_at),
                    'exception' => $this->clean(strtok((string) $r->exception, "\n") ?: null),
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function stripeWebhookProblems(Carbon $since): array
    {
        if (! $this->hasTable('stripe_webhook_events')) {
            return ['count' => 0, 'recent' => []];
        }

        $hasSignature = Schema::hasColumn('stripe_webhook_events', 'signature_valid');
        $base = fn () => StripeWebhookEvent::where('received_at', '>=', $since)
            ->where(function ($q) use ($hasSignature) {
                $q->where('status', StripeWebhookEvent::STATUS_FAILED);
                if ($hasSignature) {
                    $q->orWhere('signature_valid', false);
                }
            });

        return [
            'count' => $base()->count(),
            'recent' => $base()->latest('received_at')->limit(10)->get()
                ->map(fn (StripeWebhookEvent $e) => [
                    'type' => $e->type,
                    'status' => $e->status,
                    'signature_valid' => $hasSignature ? (bool) $e->signature_valid : null,
                    'error' => $this->clean($e->processing_error ?? $e->error_message),
                    'received_at' => $this->iso($e->received_at),
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function partnerWebhookFailures(Carbon $since): array
    {
        if (! $this->hasTable('partner_webhook_deliveries')) {
            return ['count' => 0, 'recent' => []];
        }

        $base = fn () => PartnerWebhookDelivery::where('status', PartnerWebhookDelivery::STATUS_FAILED)
            ->whereRaw('coalesce(last_attempt_at, created_at) >= ?', [$since]);

        return [
            'count' => $base()->count(),
            'recent' => $base()->with('company:id,name')
                ->orderByRaw('coalesce(last_attempt_at, created_at) desc')->limit(10)->get()
                ->map(fn (PartnerWebhookDelivery $d) => [
                    'partner_company' => $d->company?->name,
                    'event_type' => $d->event_type,
                    'attempts' => (int) $d->attempts,
                    'response_status' => $d->response_status,
                    'error' => $this->clean($d->error),
                    'last_attempt_at' => $this->iso($d->last_attempt_at ?? $d->created_at),
                ])->values()->all(),
        ];
    }

    /**
     * Current state, not windowed: a payment needing a person stays on the
     * list until someone deals with it.
     *
     * @return array<string, mixed>
     */
    private function vendorPaymentsNeedingAttention(): array
    {
        if (! $this->hasTable('vendor_payment_audits')) {
            return ['count' => 0, 'recent' => []];
        }

        $base = fn () => VendorPaymentAudit::whereIn('verdict', VendorPaymentAudit::NEEDS_ATTENTION);

        return [
            'count' => $base()->count(),
            'recent' => $base()->latest('checked_at')->limit(10)->get()
                ->map(fn (VendorPaymentAudit $a) => [
                    'vendor_lead_id' => $a->vendor_lead_id,
                    'vendor' => $a->vendor,
                    'verdict' => $a->verdict,
                    'failure_code' => $a->failure_code,
                    'decline_code' => $a->decline_code,
                    'checked_at' => $this->iso($a->checked_at),
                    'amount_cents' => $a->intent_amount === null ? null : (int) $a->intent_amount,
                ])->values()->all(),
        ];
    }

    /**
     * Current state, not windowed.
     *
     * @return array<string, mixed>
     */
    private function failedPayoutTransfers(): array
    {
        if (! $this->hasTable('commission_payouts') || ! Schema::hasColumn('commission_payouts', 'transfer_status')) {
            return ['count' => 0, 'recent' => []];
        }

        $base = fn () => CommissionPayout::where('transfer_status', 'failed');

        return [
            'count' => $base()->count(),
            'recent' => $base()->with('earner:id,name')->latest('updated_at')->limit(10)->get()
                ->map(fn (CommissionPayout $p) => [
                    'payout_id' => $p->id,
                    'earner' => $p->earner?->name,
                    'amount_cents' => $this->dollarsToCents($p->total_amount),
                    'reason' => $this->clean($p->transfer_failure_reason),
                    'updated_at' => $this->iso($p->updated_at),
                ])->values()->all(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function now(): string
    {
        return now()->toIso8601String();
    }

    private function appName(): string
    {
        return (string) ($this->hasTable('site_settings') ? SiteSetting::get('site_name') : config('app.name'));
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @return array<string, int> */
    private function countBy(string $table, string $column, ?callable $scope = null): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }

        $query = DB::table($table);
        if ($scope) {
            $scope($query);
        }

        return $query->selectRaw("{$column} as k, count(*) as n")->groupBy($column)->orderBy($column)
            ->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }

    /** The commission tables keep decimal dollars; everything leaves here in cents. */
    private function dollarsToCents(mixed $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'))->toIso8601String();
    }

    private function upper(?string $currency): ?string
    {
        return $currency === null ? null : strtoupper($currency);
    }

    /** Truncate free text and mask email addresses in it. */
    private function clean(?string $text, int $limit = self::TEXT_LIMIT): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? '';

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text) ?? ''), $limit);
    }

    private function relativePath(string $file): string
    {
        return Str::after($file, base_path().DIRECTORY_SEPARATOR);
    }

    /** The path of a URL, without host or query string. */
    private function urlPath(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return Str::limit((string) (parse_url($url, PHP_URL_PATH) ?: '/'), self::TEXT_LIMIT);
    }
}
