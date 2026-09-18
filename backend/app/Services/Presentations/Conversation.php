<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationMessage;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The private thread between a guest, the member who invited them, and admins.
 *
 * Everything that writes or reads a message goes through here, so the rule
 * about who may speak into a thread has one home rather than three.
 */
class Conversation
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** Messages in a guest's thread, oldest first. */
    public function messages(PresentationAttendee $attendee, ?int $afterId = null): Collection
    {
        return $attendee->messages()
            ->with('sender:id,name')
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId))
            ->get();
    }

    /** The guest speaking. */
    public function fromGuest(PresentationAttendee $attendee, string $body): PresentationMessage
    {
        $message = $attendee->messages()->create([
            'presentation_id' => $attendee->presentation_id,
            'sender_type'     => PresentationMessage::FROM_ATTENDEE,
            'body'            => $body,
        ]);

        $this->notifyHost($attendee, $body);

        return $message;
    }

    /**
     * A member or admin replying.
     *
     * The caller is responsible for having checked visibility; this asserts it
     * again anyway, because a reply landing in the wrong thread is the exact
     * failure the whole feature is built to prevent and it is worth paying a
     * second check for.
     */
    public function fromStaff(PresentationAttendee $attendee, User $user, string $body): PresentationMessage
    {
        abort_unless($attendee->isVisibleTo($user), 403);

        // Anything the guest had said is now read.
        $attendee->messages()
            ->where('sender_type', PresentationMessage::FROM_ATTENDEE)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $attendee->messages()->create([
            'presentation_id' => $attendee->presentation_id,
            'sender_type'     => $user->isAdmin()
                ? PresentationMessage::FROM_ADMIN
                : PresentationMessage::FROM_MEMBER,
            'sender_user_id'  => $user->id,
            'body'            => $body,
        ]);
    }

    /**
     * Every message this user may see for a showing, newer than $sinceId.
     *
     * The console loads its whole conversation set in one request and then
     * switches between prospects with no request at all — clicking a name has
     * to feel instant while a room is live, and a per-thread fetch does not.
     */
    public function feed(Presentation $presentation, ?User $user, ?int $sinceId = null): Collection
    {
        return PresentationMessage::query()
            ->visibleTo($user)
            ->where('presentation_id', $presentation->id)
            ->when($sinceId, fn ($q) => $q->where('id', '>', $sinceId))
            ->with('sender:id,name')
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * Every message across a set of showings, newer than $sinceId.
     *
     * The unified console works several overlapping rooms at once, so its poll
     * spans them rather than asking per room. Same scoping as everything else:
     * only threads belonging to this user's guests.
     */
    public function feedAcross(
        array $presentationIds,
        ?User $user,
        ?int $sinceId = null,
        array $fullHistoryFor = [],
    ): Collection {
        if ($presentationIds === []) {
            return collect();
        }

        return PresentationMessage::query()
            ->visibleTo($user)
            ->whereIn('presentation_id', $presentationIds)
            ->where(function ($q) use ($sinceId, $fullHistoryFor) {
                /*
                 * A room that appears part-way through a session — a later call
                 * starting while the console is already open — needs its whole
                 * history, not the delta. The client's cursor is already past
                 * anything said in it before it opened, so asking only for
                 * "newer than X" would silently skip those conversations.
                 */
                if ($fullHistoryFor !== []) {
                    $q->whereIn('presentation_id', $fullHistoryFor);
                }

                if ($sinceId) {
                    $fullHistoryFor === []
                        ? $q->where('id', '>', $sinceId)
                        : $q->orWhere('id', '>', $sinceId);
                }
            })
            ->with('sender:id,name')
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /** Latest message per thread across several showings. */
    public function latestPerThreadAcross(array $presentationIds, ?User $user): Collection
    {
        if ($presentationIds === []) {
            return collect();
        }

        $ids = PresentationMessage::query()
            ->visibleTo($user)
            ->whereIn('presentation_id', $presentationIds)
            ->selectRaw('attendee_id, MAX(id) as latest_id')
            ->groupBy('attendee_id')
            ->pluck('latest_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return PresentationMessage::whereIn('id', $ids)->get()->keyBy('attendee_id');
    }

    /** Unread guest messages per thread across several showings. */
    public function unreadAcross(array $presentationIds, ?User $user): Collection
    {
        if ($presentationIds === []) {
            return collect();
        }

        return PresentationMessage::query()
            ->visibleTo($user)
            ->whereIn('presentation_id', $presentationIds)
            ->where('sender_type', PresentationMessage::FROM_ATTENDEE)
            ->whereNull('read_at')
            ->selectRaw('attendee_id, count(*) as total')
            ->groupBy('attendee_id')
            ->pluck('total', 'attendee_id');
    }

    /** The most recent message in each thread, for the list preview. */
    public function latestPerThread(Presentation $presentation, ?User $user): Collection
    {
        $ids = PresentationMessage::query()
            ->visibleTo($user)
            ->where('presentation_id', $presentation->id)
            ->selectRaw('attendee_id, MAX(id) as latest_id')
            ->groupBy('attendee_id')
            ->pluck('latest_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return PresentationMessage::whereIn('id', $ids)->get()->keyBy('attendee_id');
    }

    /**
     * Mark a guest's questions read.
     *
     * Reading is what clears the badge — a member should not have to reply to
     * make an answered-in-person question stop shouting at them.
     */
    public function markRead(PresentationAttendee $attendee, User $user): void
    {
        abort_unless($attendee->isVisibleTo($user), 403);

        $attendee->messages()
            ->where('sender_type', PresentationMessage::FROM_ATTENDEE)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Unread guest messages per thread, for the badge on a member's panel. */
    public function unreadCounts(Presentation $presentation, ?User $user): Collection
    {
        return PresentationMessage::query()
            ->visibleTo($user)
            ->where('presentation_id', $presentation->id)
            ->where('sender_type', PresentationMessage::FROM_ATTENDEE)
            ->whereNull('read_at')
            ->selectRaw('attendee_id, count(*) as total')
            ->groupBy('attendee_id')
            ->pluck('total', 'attendee_id');
    }

    /**
     * Tell the inviting member their guest said something.
     *
     * Only the host — never the whole admin team by push, which would mean an
     * alert per guest per question on a busy showing. Admins watch the inbox.
     */
    private function notifyHost(PresentationAttendee $attendee, string $body): void
    {
        $host = $attendee->host;

        if (! $host) {
            return;
        }

        try {
            $this->notifications->sendToUser($host, [
                'type'  => 'presentation_message',
                'title' => $attendee->name.' asked a question',
                'body'  => \Illuminate\Support\Str::limit($body, 120),
                'url'   => $this->consoleUrl($attendee),
                'data'  => ['attendee_id' => $attendee->id],
            ]);
        } catch (\Throwable $e) {
            // A failed notification must never cost the guest their message.
            Log::warning('Could not notify a host about a presentation message', [
                'attendee' => $attendee->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deep link straight to this guest's conversation.
     *
     * A notification that drops the member on a list, leaving them to find the
     * person it was about, wastes the one moment that matters — so it carries
     * the guest, and the console opens that thread on arrival.
     */
    private function consoleUrl(PresentationAttendee $attendee): string
    {
        // Your Rooms is the one place conversations happen, so every link into
        // one points there — whichever call, whichever flow, however old.
        return route('member.presentations.live').'?guest='.$attendee->conversationAnchor()->id;
    }

    /**
     * Tell the host what their prospect just picked.
     *
     * More useful than another arrival notice: a choice says what somebody
     * wants. "Casey chose Show me the numbers" is an opening line; "Casey is
     * still watching" is not.
     */
    public function notifyHostOfChoice(
        \App\Models\FunnelParticipant $participant,
        \App\Models\FunnelChoiceEvent $event,
    ): void {
        $host   = $participant->host;
        $anchor = $participant->threadAttendee;

        if (! $host || ! $anchor) {
            return;
        }

        try {
            $this->notifications->sendToUser($host, [
                'type'  => 'presentation_choice',
                'title' => $participant->name.' chose "'.\Illuminate\Support\Str::limit($event->label, 40).'"',
                'body'  => $event->isBranch()
                    ? 'They are watching '.($event->nextPresentation?->title ?: 'the next video').' now.'
                    : 'They asked for it from the video — worth a word now.',
                'url'   => $this->consoleUrl($anchor),
                'data'  => ['attendee_id' => $anchor->id, 'participant_id' => $participant->id],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not notify a host about a funnel choice', [
                'participant' => $participant->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** Tell the host their guest has arrived — the moment they want to reach out. */
    public function notifyHostOfArrival(PresentationAttendee $attendee): void
    {
        $host = $attendee->host;

        if (! $host) {
            return;
        }

        try {
            $this->notifications->sendToUser($host, [
                'type'  => 'presentation_guest_joined',
                'title' => $attendee->presentation?->isOnDemand()
                    ? $attendee->name.' started your video'
                    : $attendee->name.' joined your presentation',
                'body'  => 'Say hello — they are watching now.',
                'url'   => $this->consoleUrl($attendee),
                'data'  => ['attendee_id' => $attendee->id],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not notify a host about an arrival', [
                'attendee' => $attendee->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
