<?php

namespace App\Services\Presentations;

use App\Models\FunnelChoiceEvent;
use App\Models\FunnelParticipant;
use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationCue;
use App\Models\PresentationFunnel;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Moving one person through a funnel.
 *
 * Every step is an ordinary presentation and every arrival is an ordinary
 * attendee row, so the room, the chat, the isolation and the reporting all work
 * on a funnel without knowing it is one. What this service adds is the thread
 * running through them: the same person, the same inviting member, from the
 * first video to whatever they choose at the end.
 */
class FunnelJourney
{
    public function __construct(
        private readonly AttendeeRegistrar $registrar,
        private readonly Conversation $conversation,
    ) {
    }

    /**
     * Register somebody at the top of a flow.
     *
     * Attribution is settled here and never revisited. A participant already
     * belonging to this member is returned as-is, because a prospect coming
     * back to a link they were sent last week is the normal case and must not
     * become a second journey.
     */
    public function enter(
        PresentationFunnel $funnel,
        array $details,
        ?User $host,
        ?Request $request = null,
    ): FunnelParticipant {
        $entry = $funnel->entry;

        abort_unless($entry !== null, 404, 'This flow has no first video yet.');

        $email = mb_strtolower(trim($details['email']));

        $existing = FunnelParticipant::where('funnel_id', $funnel->id)
            ->where('host_user_id', $host?->id)
            ->where('email', $email)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->start($funnel, $entry, $email, $details, $host, $request));
        } catch (QueryException $e) {
            // Two tabs, one prospect, same instant. The unique index decides
            // it and the loser re-reads the winner.
            $winner = FunnelParticipant::where('funnel_id', $funnel->id)
                ->where('host_user_id', $host?->id)
                ->where('email', $email)
                ->first();

            if (! $winner) {
                throw $e;
            }

            return $winner;
        }
    }

    private function start(
        PresentationFunnel $funnel,
        Presentation $entry,
        string $email,
        array $details,
        ?User $host,
        ?Request $request,
    ): FunnelParticipant {
        $participant = FunnelParticipant::create([
            'funnel_id'               => $funnel->id,
            'host_user_id'            => $host?->id,
            'name'                    => trim($details['name']),
            'email'                   => $email,
            'phone'                   => $details['phone'] ?? null,
            'current_presentation_id' => $entry->id,
            'started_at'              => now(),
        ]);

        $attendee = $this->attendeeFor($participant, $entry, $host, $request);

        // The first room they land in is where the whole conversation lives,
        // however many videos they go on to watch.
        $participant->forceFill(['thread_attendee_id' => $attendee->id])->save();

        return $participant->refresh();
    }

    /**
     * Their attendee row on one video, created if this is their first arrival.
     *
     * Goes through the ordinary registrar so a funnel guest is subject to the
     * same attribution rules as anybody else — including the claim row when a
     * second member's link reaches somebody who is already spoken for.
     */
    public function attendeeFor(
        FunnelParticipant $participant,
        Presentation $presentation,
        ?User $host = null,
        ?Request $request = null,
    ): PresentationAttendee {
        $attendee = $this->registrar->register(
            $presentation,
            [
                'name'  => $participant->name,
                'email' => $participant->email,
                'phone' => $participant->phone,
            ],
            $host ?? $participant->host,
            $request,
        );

        if ($attendee->funnel_participant_id !== $participant->id) {
            $attendee->forceFill(['funnel_participant_id' => $participant->id])->save();
        }

        return $attendee;
    }

    /**
     * Record a choice and act on it.
     *
     * Returns where to send the person: another video, or a destination that
     * ends the journey. The event is written first and unconditionally — a
     * choice we acted on but failed to record is worse than one we recorded
     * twice, because the record is the product.
     *
     * @return array{event: FunnelChoiceEvent, next: ?Presentation, url: ?string}
     */
    public function choose(
        FunnelParticipant $participant,
        PresentationCue $cue,
        ?int $atSeconds = null,
        ?Request $request = null,
    ): array {
        $presentation = $cue->presentation;

        $event = FunnelChoiceEvent::create([
            'funnel_id'            => $participant->funnel_id,
            'participant_id'       => $participant->id,
            'presentation_id'      => $presentation?->id,
            'cue_id'               => $cue->id,
            'kind'                 => $cue->kind,
            // Snapshotted: the cue can be reworded tomorrow, this cannot.
            'label'                => \Illuminate\Support\Str::limit($cue->buttonLabel(), 120, ''),
            'next_presentation_id' => $cue->next_presentation_id,
            'cta_item_id'          => $cue->cta_item_id,
            'at_seconds'           => $atSeconds,
        ]);

        $participant->forceFill(['last_seen_at' => now()])->save();

        $this->conversation->notifyHostOfChoice($participant, $event);

        if ($cue->isBranch()) {
            $next = $cue->nextPresentation;

            if ($next) {
                $this->advance($participant, $next, $request);
            }

            return ['event' => $event, 'next' => $next, 'url' => null];
        }

        $cta = $cue->resolveCta($participant->host);

        // What they asked for, not what they went on to do. Whether the sign-up
        // or the booking actually happened is the conversion tracker's
        // question, and it answers it from the other end.
        $outcome = FunnelParticipant::outcomeForCtaKind($cue->ctaItem?->kind);

        $participant->forceFill([
            'outcome'     => $outcome ?? $participant->outcome,
            'finished_at' => $participant->finished_at ?? now(),
        ])->save();

        return ['event' => $event, 'next' => null, 'url' => $cta?->url];
    }

    /**
     * Put them on the next video.
     *
     * The host is taken from the participant rather than from whatever link
     * they happen to be holding, which is the point of having a participant at
     * all: the member who invited them owns the whole journey.
     */
    public function advance(
        FunnelParticipant $participant,
        Presentation $next,
        ?Request $request = null,
    ): PresentationAttendee {
        $attendee = $this->attendeeFor($participant, $next, $participant->host, $request);

        $participant->forceFill([
            'current_presentation_id' => $next->id,
            'last_seen_at'            => now(),
        ])->save();

        return $attendee;
    }

    /** Keep the journey's presence current, alongside the attendee's own. */
    public function touch(FunnelParticipant $participant, ?Presentation $on = null): void
    {
        $participant->forceFill(array_filter([
            'last_seen_at'            => now(),
            'current_presentation_id' => $on?->id,
        ]))->save();
    }

    /**
     * The path somebody took, as a readable list.
     *
     * Used by the console and the report. Deliberately built from the choice
     * events rather than from their attendee rows — the order they chose things
     * in is the story, and attendee rows only say where they ended up.
     */
    public function path(FunnelParticipant $participant): array
    {
        return $participant->choices()
            ->with(['presentation:id,title', 'nextPresentation:id,title'])
            ->get()
            ->map(fn (FunnelChoiceEvent $e) => [
                'from'  => $e->presentation?->title,
                'chose' => $e->label,
                'to'    => $e->nextPresentation?->title,
                'kind'  => $e->kind,
                'at'    => $e->formattedAt(),
                'when'  => $e->created_at,
            ])->all();
    }
}
