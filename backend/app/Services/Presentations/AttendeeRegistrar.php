<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationAttendeeClaim;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers a guest for a showing and decides who they belong to.
 *
 * The attribution rule is the commercially load-bearing part: **the first
 * member to register a guest for a showing keeps them.** A second member
 * arriving with the same email gets a claim row and nothing else — they are not
 * told the guest exists, because telling them would leak across exactly the
 * boundary this feature is built to hold.
 */
class AttendeeRegistrar
{
    /**
     * @param  User|null  $host  the member whose link was used, if any
     */
    public function register(
        Presentation $presentation,
        array $details,
        ?User $host,
        ?Request $request = null,
    ): PresentationAttendee {
        $email = mb_strtolower(trim($details['email']));

        // Registering twice from the same link is the common case — a guest
        // closes the tab and comes back — and must return the same row rather
        // than colliding on the unique index.
        $existing = $presentation->attendees()->where('email', $email)->first();

        if ($existing) {
            return $this->handleExisting($existing, $host, $details);
        }

        try {
            return DB::transaction(fn () => $this->create($presentation, $email, $details, $host, $request));
        } catch (QueryException $e) {
            // Two people registering the same email in the same instant: the
            // unique index decides it, and the loser re-reads the winner.
            $winner = $presentation->attendees()->where('email', $email)->first();

            if (! $winner) {
                throw $e;
            }

            return $this->handleExisting($winner, $host, $details);
        }
    }

    private function create(
        Presentation $presentation,
        string $email,
        array $details,
        ?User $host,
        ?Request $request,
    ): PresentationAttendee {
        return $presentation->attendees()->create([
            'host_user_id'  => $host?->id,
            'name'          => trim($details['name']),
            'email'         => $email,
            'phone'         => $details['phone'] ?? null,
            'token'         => Str::random(48),
            'registered_at' => now(),
            'ip_address'    => $request?->ip(),
            'user_agent'    => Str::limit((string) $request?->userAgent(), 500, ''),
            'utm_source'    => $request?->query('utm_source'),
            'utm_medium'    => $request?->query('utm_medium'),
            'utm_campaign'  => $request?->query('utm_campaign'),
        ]);
    }

    /**
     * The guest is already registered for this showing.
     *
     * If a *different* member's link brought them this time, record the claim
     * and leave the original attribution alone.
     */
    private function handleExisting(
        PresentationAttendee $attendee,
        ?User $host,
        array $details,
    ): PresentationAttendee {
        if ($host && $attendee->host_user_id !== $host->id) {
            PresentationAttendeeClaim::firstOrCreate([
                'presentation_id'    => $attendee->presentation_id,
                'attendee_id'        => $attendee->id,
                'claimed_by_user_id' => $host->id,
            ]);
        }

        // An unattributed guest who later arrives on a member's link is adopted
        // by that member. Nobody loses anything — they belonged to no one — and
        // it rewards the member who actually did the inviting.
        if ($host && $attendee->host_user_id === null) {
            $attendee->forceFill(['host_user_id' => $host->id])->save();
        }

        // Let a returning guest correct a typo in their own name.
        if (filled($details['name'] ?? null) && $attendee->name !== trim($details['name'])) {
            $attendee->forceFill(['name' => trim($details['name'])])->save();
        }

        return $attendee->refresh();
    }

    /**
     * Record that the guest is in the room, and how far in they arrived.
     *
     * `joined_at_offset` is stamped once, server-side. It is the number a member
     * actually acts on — everyone is synced, so what differs between guests is
     * where they came in.
     *
     * @return bool true if this was their first arrival, so the caller can
     *              alert the host exactly once
     */
    public function markJoined(PresentationAttendee $attendee, Presentation $presentation): bool
    {
        if ($attendee->first_joined_at !== null) {
            return false;
        }

        $attendee->forceFill([
            'first_joined_at' => now(),
            // Where they came in. Always zero for an always-open share, because
            // it starts at the beginning for whoever opens it.
            'joined_at_offset' => $presentation->isOnDemand()
                ? 0
                : ($presentation->currentOffset() ?? 0),
        ])->save();

        return true;
    }

    /**
     * A heartbeat from the guest's player.
     *
     * Watch time is accumulated from the gap since the last heartbeat, capped
     * at slightly more than the expected interval — so a client that stops
     * reporting and resumes an hour later adds one interval, not an hour, and a
     * client that lies about its position cannot inflate its watch time at all.
     */
    public function heartbeat(PresentationAttendee $attendee, Presentation $presentation, ?int $position): void
    {
        $now  = now();
        $last = $attendee->last_seen_at;

        $elapsed = $last === null
            ? 0
            : (int) $last->diffInSeconds($now, absolute: true);

        $credit = min($elapsed, self::MAX_HEARTBEAT_CREDIT);

        /*
         * How far a reported position is allowed to be.
         *
         * On a shared showing it is the room's own clock — nobody can be ahead
         * of the room. On an always-open share there is no room clock, so the
         * only ceiling is the length of the video itself.
         */
        $ceiling = $presentation->isOnDemand()
            ? (int) $presentation->duration_seconds
            : ($presentation->currentOffset() ?? 0);

        $reported = $position === null ? null : max(0, min($position, $ceiling));

        $attendee->forceFill([
            'last_seen_at'     => $now,
            'watch_seconds'    => $attendee->watch_seconds + $credit,
            'position_seconds' => $reported ?? $attendee->position_seconds,
            // Only ever moves forward: it is what they have seen, not where
            // they happen to be sitting after scrubbing back over something.
            'furthest_seconds' => max((int) $attendee->furthest_seconds, (int) ($reported ?? 0)),
        ])->save();
    }

    /** Slightly more than the client's 20s interval, to absorb jitter. */
    private const MAX_HEARTBEAT_CREDIT = 30;
}
