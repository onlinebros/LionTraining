<?php

namespace App\Services\Partner;

use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\PartnerCompany;
use App\Models\PartnerWebhookDelivery;
use App\Models\User;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Telling a partner company that one of their positions now has an owner.
 *
 * Two halves, deliberately split: building and recording the event (in the
 * request that claims the spot) and delivering it (on the queue). A claim must
 * not fail, or hang, because the partner's endpoint is down — the member is
 * standing in front of the form.
 *
 * ── What goes in the payload ─────────────────────────────────────────────────
 *
 * Always: their identifier for the position, when it was claimed, and where it
 * sits. That is what they need to reconcile against their own list, and it says
 * nothing about the person they did not already know.
 *
 * Only when the company has `webhook_include_contact` on: the member's name,
 * email and phone. This is a disclosure of somebody's personal data to a third
 * party, so it is a switch somebody throws per company, with the claim page's
 * wording updated to say so — not a default. The import deliberately holds no
 * personal data (see SpotImportTemplate); this is the one place any flows the
 * other way, and it should stay easy to find.
 *
 * ── Signing ──────────────────────────────────────────────────────────────────
 *
 * HMAC-SHA256 over "{timestamp}.{body}", in a `Q3-Signature: t=…,v1=…` header.
 * Deliberately the same scheme Stripe uses: every partner's engineers have
 * already written the five lines that verify it, and a scheme they recognise is
 * a scheme they actually check rather than skip.
 */
class PartnerWebhookDispatcher
{
    /** Response bodies are stored for diagnosis, not archived. */
    private const MAX_RESPONSE_BYTES = 2000;

    public function __construct(private GenealogyService $genealogy) {}

    /**
     * Record and queue the event for a spot that has just been claimed.
     *
     * Returns null when the company has no webhook configured, which is the
     * normal case for a partner who only wanted the import. Never throws: a
     * misconfigured integration must not be able to fail a claim.
     */
    public function spotClaimed(User $spot, ?User $mergedInto = null): ?PartnerWebhookDelivery
    {
        $company = $spot->partnerCompany;

        if ($company === null || ! $this->isConfigured($company)) {
            return null;
        }

        // Recorded here, inside whatever transaction is claiming the position,
        // so the payload is frozen against the state that is actually being
        // committed. Delivery is deferred — see record().
        $delivery = $this->record(
            $company,
            PartnerWebhookDelivery::EVENT_SPOT_CLAIMED,
            $this->spotClaimedPayload($company, $spot, $mergedInto),
            $spot,
        );

        $this->queue($delivery);

        return $delivery;
    }

    /** A hand-fired event with no spot behind it, to check an endpoint. */
    public function ping(PartnerCompany $company): PartnerWebhookDelivery
    {
        $delivery = $this->record($company, PartnerWebhookDelivery::EVENT_PING, [
            'message' => 'Test event from Quantum 3 Solution. No spot was claimed.',
        ]);

        $this->queue($delivery);

        return $delivery;
    }

    /**
     * Send one recorded delivery, updating it with the outcome.
     *
     * @return bool Whether the partner accepted it.
     */
    public function deliver(PartnerWebhookDelivery $delivery): bool
    {
        $company = $delivery->company;

        if ($company === null || ! $this->isConfigured($company)) {
            $delivery->update([
                'status' => PartnerWebhookDelivery::STATUS_FAILED,
                'error'  => 'The company no longer has a webhook configured.',
            ]);

            return false;
        }

        $body = $this->encode($delivery);

        $delivery->increment('attempts');
        $delivery->update(['last_attempt_at' => Carbon::now()]);

        try {
            $response = Http::withHeaders($this->headers($company, $delivery, $body))
                // Short, and connect-timeout shorter still. This runs on the
                // queue, but a partner endpoint that accepts a connection and
                // then never answers would otherwise hold a worker for minutes
                // per attempt.
                ->connectTimeout(5)
                ->timeout(15)
                ->withBody($body, 'application/json')
                ->post((string) $company->webhook_url);
        } catch (\Throwable $e) {
            $this->recordFailure($delivery, $company, null, null, $e->getMessage());

            return false;
        }

        if ($response->successful()) {
            $delivery->update([
                'status'          => PartnerWebhookDelivery::STATUS_DELIVERED,
                'response_status' => $response->status(),
                'response_body'   => Str::limit($response->body(), self::MAX_RESPONSE_BYTES),
                'error'           => null,
                'delivered_at'    => Carbon::now(),
            ]);

            $company->forceFill([
                'webhook_last_success_at'      => Carbon::now(),
                'webhook_consecutive_failures' => 0,
            ])->save();

            return true;
        }

        $this->recordFailure(
            $delivery,
            $company,
            $response->status(),
            $response->body(),
            "The endpoint answered {$response->status()}.",
        );

        return false;
    }

    /** Give up on a delivery — the job's retries are exhausted. */
    public function abandon(PartnerWebhookDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => PartnerWebhookDelivery::STATUS_FAILED,
            'error'  => $reason,
        ]);
    }

    /**
     * The event body, exactly as it will be signed and sent.
     *
     * Public because the admin screen shows it: an integration that is not
     * working is usually a disagreement about the shape of this, and the fastest
     * way to end that is to put it on screen next to the response.
     */
    public function encode(PartnerWebhookDelivery $delivery): string
    {
        return json_encode([
            'id'      => $delivery->event_id,
            'type'    => $delivery->event_type,
            'created' => $delivery->created_at?->timestamp ?? Carbon::now()->timestamp,
            'data'    => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The headers a delivery goes out with, signed the way this partner reads.
     *
     * Public so the admin screen can show them beside the payload: an
     * integration that is not working is usually a disagreement about a header,
     * and the fastest way to end that is to put ours on screen.
     *
     * The secret is never among them — only what it produces.
     *
     * @return array<string, string>
     */
    public function headers(PartnerCompany $company, PartnerWebhookDelivery $delivery, string $body): array
    {
        $secret = (string) $company->webhook_secret;

        $headers = [
            'Content-Type'  => 'application/json',
            // Sent whatever the signature style, because they are what a
            // partner keys idempotency on and that is not negotiable.
            'Q3-Event-Id'   => $delivery->event_id,
            'Q3-Event-Type' => $delivery->event_type,
            'User-Agent'    => 'Quantum3Solution-Webhooks/1',
        ];

        if ($company->webhook_signature_style === PartnerCompany::SIGNATURE_SHA256) {
            // GitHub's convention: the body alone, no timestamp. iHub's
            // receiver reads this one.
            $headers['X-Partner-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

            return $headers;
        }

        $timestamp = Carbon::now()->timestamp;
        $headers['Q3-Signature'] = $this->signature($body, $timestamp, $secret);

        return $headers;
    }

    /** Stripe's convention: the timestamp is part of what is signed. */
    public function signature(string $body, int $timestamp, string $secret): string
    {
        return "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function isConfigured(PartnerCompany $company): bool
    {
        return $company->webhook_enabled
            && filled($company->webhook_url)
            && filled($company->webhook_secret);
    }

    /**
     * Everything we can say about a claimed spot.
     *
     * @return array<string, mixed>
     */
    private function spotClaimedPayload(PartnerCompany $company, User $spot, ?User $mergedInto = null): array
    {
        $parent  = $spot->placementParent;
        $directs = $this->genealogy->directSpotCounts($spot);

        $payload = [
            // Their identifier first: it is the key they will join on.
            'external_user_id' => $spot->external_user_id,
            'claimed_at'       => $spot->claimed_at?->toIso8601String(),
            'imported_at'      => $spot->imported_at?->toIso8601String(),

            'quantum' => [
                'user_id'       => $spot->id,
                'referral_code' => $spot->referral_code,
                // Their own invitation link, so the partner can put it straight
                // in front of the member in their system.
                'referral_url'  => route('join', $spot->referral_code),
            ],

            'position' => [
                // Their identifier for the position above, when that position
                // also came from their list. Null when the leg hangs beneath a
                // Quantum partner, which is the case for every leg top.
                'external_parent_id'     => $parent?->external_user_id,
                'parent_quantum_user_id' => $parent?->id,
                'parent_is_partner_spot' => $parent?->isImported() ?? false,
                'depth'                  => $this->genealogy->depthOf($spot->placement_path),
                'placed_at'              => $spot->placed_at?->toIso8601String(),

                // The first level only, and counted that way on purpose.
                //
                // Totalling a whole downline means an aggregate over an ltree
                // range that, for a position near the top of an organisation
                // this size, is a million rows and seconds of work — inside the
                // transaction that is claiming the position, with the member
                // waiting on the form. The partner has their own copy of the
                // tree and can total it themselves; what only we know is that
                // this position just activated.
                'directs_claimed'        => $directs['claimed'],
                'directs_unclaimed'      => $directs['unclaimed'],
            ],

            'membership' => [
                // Whether they have completed enrollment yet. A claim and a
                // paying member are not the same event, and a partner counting
                // conversions needs to be able to tell them apart.
                'active'          => ($mergedInto ?? $spot)->hasActiveMembership(),
                'billing_pending' => ($mergedInto ?? $spot)->isOnCommissionHold(),
            ],
        ];

        if ($mergedInto !== null) {
            // The position was claimed by somebody who already had an account
            // here — a founder, usually — so it was folded into that account
            // rather than becoming its own. Everyone who was below this
            // position is now below `merged_into`. Said explicitly because a
            // partner reconciling their tree against ours will otherwise find
            // this position's downline apparently reparented for no reason.
            $payload['merged_into'] = [
                'quantum_user_id'  => $mergedInto->id,
                'referral_code'    => $mergedInto->referral_code,
                'external_user_id' => $mergedInto->external_user_id,
            ];
        }

        if ($company->webhook_include_contact) {
            // See the class note. Present only because somebody turned it on
            // for this company.
            $payload['member'] = [
                'name'  => $spot->name,
                'email' => $spot->email,
                'phone' => $spot->phone,
                'city'  => $spot->city,
                'state' => $spot->state,
                'country' => $spot->country,
            ];
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    /**
     * Hand the delivery to the queue, after the current transaction commits.
     *
     * afterCommit matters: a worker that picks the job up before the claim is
     * committed would deliver an event describing something that has not
     * happened, and — if the transaction then rolled back — never did.
     */
    private function queue(PartnerWebhookDelivery $delivery): void
    {
        DeliverPartnerWebhookJob::dispatch($delivery->id)->afterCommit();
    }

    private function record(
        PartnerCompany $company,
        string $type,
        array $payload,
        ?User $spot = null,
    ): PartnerWebhookDelivery {
        return PartnerWebhookDelivery::create([
            'partner_company_id' => $company->id,
            // Ours and stable across every retry and replay, so the partner can
            // key their idempotency on it.
            'event_id'           => (string) Str::uuid(),
            'event_type'         => $type,
            'user_id'            => $spot?->id,
            'external_user_id'   => $spot?->external_user_id,
            'payload'            => $payload,
            'status'             => PartnerWebhookDelivery::STATUS_PENDING,
        ]);
    }

    private function recordFailure(
        PartnerWebhookDelivery $delivery,
        PartnerCompany $company,
        ?int $status,
        ?string $body,
        string $error,
    ): void {
        $delivery->update([
            'response_status' => $status,
            'response_body'   => $body === null ? null : Str::limit($body, self::MAX_RESPONSE_BYTES),
            'error'           => Str::limit($error, self::MAX_RESPONSE_BYTES),
        ]);

        $company->increment('webhook_consecutive_failures');
    }
}
