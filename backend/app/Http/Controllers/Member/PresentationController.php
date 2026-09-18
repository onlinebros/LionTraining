<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\User;
use App\Models\PresentationAttendee;
use App\Models\ScreenRecording;
use App\Services\ScreenRecording\RecordingStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Services\Presentations\Conversation;
use App\Services\Presentations\ProspectReport;
use App\Services\Presentations\ProspectToCrm;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

/**
 * A member's view of a showing: their own link, and their own guests.
 *
 * Every query here goes through `visibleTo()`. There is no code path in this
 * controller that can return another member's guest, and the isolation test
 * suite asserts that by trying.
 */
class PresentationController extends Controller
{
    public function __construct(private readonly Conversation $conversation)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('member.presentations.index', [
            'user'     => $user,
            'upcoming' => Presentation::forMember($user)->upcoming()->with(['recording', 'owner'])->get(),
            'past'     => Presentation::forMember($user)->past()->with(['recording', 'owner'])->limit(20)->get(),
            'canSchedule' => $this->schedulingIsOpen($user),
            'mineUpcoming' => Presentation::where('owner_user_id', $user->id)
                ->where('scheduled_at', '>=', now())->count(),
            'limit' => (int) config('presentations.member_upcoming_limit'),
            // Their own counts only — deliberately not a global leaderboard.
            'myCounts' => PresentationAttendee::query()
                ->visibleTo($user)
                ->when(! $user->isAdmin(), fn ($q) => $q->where('host_user_id', $user->id))
                ->selectRaw('presentation_id, count(*) as total')
                ->groupBy('presentation_id')
                ->pluck('total', 'presentation_id'),
        ]);
    }

    public function show(Request $request, Presentation $presentation)
    {
        $user = $request->user();

        $this->assertReachable($request, $presentation);

        return view('member.presentations.show', [
            'user'         => $user,
            'presentation' => $presentation->load('recording'),
            'shareUrl'     => $presentation->watchUrlFor($user),
            'attendees'    => $this->attendeesFor($request, $presentation),
        ]);
    }

    /**
     * The live panel's data, polled every few seconds.
     *
     * Returns the room's current position alongside the guests so the member
     * sees "now playing 14:02" and the guest rows from one consistent read.
     */
    /**
     * Everything the console needs, in one request.
     *
     * Room state, every guest, and every message newer than `since` across all
     * of this member's threads. One poll rather than one per open conversation,
     * and the client holds the whole set — so clicking between prospects is
     * instant, which is the difference between a usable room and a chore.
     */
    public function attendees(Request $request, Presentation $presentation): JsonResponse
    {
        $this->assertReachable($request, $presentation);

        $user      = $request->user();
        $attendees = $this->attendeesFor($request, $presentation);
        $unread    = $this->conversation->unreadCounts($presentation, $user);
        $latest    = $this->conversation->latestPerThread($presentation, $user);
        $chapter   = $presentation->currentChapter();

        $since    = $request->integer('since') ?: null;
        $messages = $this->conversation->feed($presentation, $user, $since);

        return response()->json([
            'status'   => $presentation->status,
            'offset'   => $presentation->currentOffset(),
            'duration' => $presentation->duration_seconds,
            'chapter'  => $chapter?->label,
            'watching' => $attendees->filter->isWatching()->count(),
            'total'    => $attendees->count(),
            'unread'   => (int) $unread->sum(),
            'attendees' => $attendees->map(function (PresentationAttendee $a) use ($unread, $latest) {
                $last = $latest->get($a->id);

                return [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'email'       => $a->email,
                    'joined_at'   => $a->formattedJoinOffset(),
                    'watched'     => $a->formattedWatchTime(),
                    'watching'    => $a->isWatching(),
                    'registered'  => $a->registered_at->diffForHumans(),
                    'cta_clicked' => $a->cta_clicked_at !== null,
                    'unread'      => (int) ($unread[$a->id] ?? 0),
                    'preview'     => $last ? Str::limit($last->body, 60) : null,
                    'preview_mine'=> $last ? ! $last->isFromGuest() : false,
                    'last_id'     => $last?->id ?? 0,
                ];
            })->values(),
            'messages' => $messages->map(fn ($m) => [
                'id'       => $m->id,
                'attendee' => $m->attendee_id,
                'guest'    => $m->isFromGuest(),
                'from'     => $m->displayName(),
                'body'     => $m->body,
                'at'       => $m->created_at->copy()->setTimezone(Presentation::bookingTimezone())->format('g:ia'),
            ])->values(),
        ]);
    }

    /**
     * Every room this member currently has people in, in one console.
     *
     * Calls overlap — two showings can be running while a third is about to
     * start — and a member with guests spread across them should not be
     * switching browser tabs to answer them. Rooms are shown together, each
     * guest labelled with the call they are in.
     */
    public function live(Request $request)
    {
        return view('member.presentations.live', [
            'rooms' => $this->activeRooms($request->user(), $request->integer('guest') ?: null),
        ]);
    }

    /**
     * The unified feed: rooms, guests and messages across all of them.
     *
     * One request rather than one per room, for the same reason the
     * per-presentation console uses one — the client holds everything and
     * switching between people, or between rooms, costs nothing.
     */
    public function liveFeed(Request $request): JsonResponse
    {
        $user  = $request->user();
        $rooms = $this->activeRooms($user, $request->integer('guest') ?: null);
        $ids   = $rooms->pluck('id')->all();

        $attendees = PresentationAttendee::query()
            ->visibleTo($user)
            ->whereIn('presentation_id', $ids)
            ->orderByDesc('first_joined_at')
            ->orderBy('name')
            ->get()
            ->each(fn (PresentationAttendee $a) => $a->setRelation(
                'presentation', $rooms->firstWhere('id', $a->presentation_id)
            ));

        // A funnel prospect has an attendee row on every video they have
        // reached. In the console they are one person, on whichever video they
        // are on now — three rows for the same human would be three people to
        // answer.
        $attendees = $this->collapseJourneys($attendees);

        $unread = $this->conversation->unreadAcross($ids, $user);
        $latest = $this->conversation->latestPerThreadAcross($ids, $user);
        $since  = $request->integer('since') ?: null;

        /*
         * Rooms the console does not know about yet — a later call that has
         * just opened while it sat there. Those need their full history, since
         * the client's cursor is already past anything said in them.
         */
        $known = collect(explode(',', (string) $request->query('known', '')))
            ->map(fn ($value) => (int) trim($value))
            ->filter()
            ->all();

        // Opt-in. A caller that does not say what it holds gets the plain delta
        // rather than being handed every room's full history — silence is not
        // the same as "I know about nothing".
        $fresh = ($since === null || $known === [])
            ? []
            : array_values(array_diff($ids, $known));

        return response()->json([
            'rooms' => $rooms->map(fn (Presentation $p) => [
                'id'       => $p->id,
                'title'    => $p->title,
                'slug'     => $p->slug,
                'status'   => $p->status,
                'offset'   => $p->currentOffset(),
                'duration' => $p->duration_seconds,
                'chapter'  => $p->currentChapter()?->label,
                'starts'   => $p->scheduledLabel(),
                'format'   => $p->format,
                // Steps of the same flow are shown as one thing in the console:
                // six videos a prospect might take three of is one conversation,
                // not six rooms.
                'funnel'   => $p->funnel_id ? [
                    'id'    => $p->funnel_id,
                    'title' => $p->funnel?->title,
                ] : null,
                'video'    => route('member.presentations.video', $p->slug),
                // Tells the console to announce it rather than quietly grow.
                'is_new'   => in_array($p->id, $fresh, true),
            ])->values(),

            'attendees' => $attendees->map(function (PresentationAttendee $a) use ($unread, $latest) {
                $last = $latest->get($a->id);

                // Where they are now. For a funnel prospect that is a
                // different video from the one their conversation hangs off.
                $at = $a->relationLoaded('journeyCurrent') ? $a->getRelation('journeyCurrent') : $a;

                return [
                    'id'           => $a->id,
                    'room'         => $at->presentation_id,
                    'name'         => $a->name,
                    'email'        => $a->email,
                    'joined_at'    => $at->formattedJoinOffset(),
                    'joined_secs'  => $at->joined_at_offset,
                    'position'     => $at->position_seconds,
                    'furthest'     => $at->furthest_seconds,
                    'progress'     => $at->progressPercent((int) ($at->presentation?->duration_seconds ?? 0)),
                    'watched'      => $a->formattedWatchTime(),
                    'watching'     => $at->isWatching(),
                    'cta_clicked'  => $a->cta_clicked_at !== null,
                    'unread'       => (int) ($unread[$a->id] ?? 0),
                    'preview'      => $last ? Str::limit($last->body, 60) : null,
                    'preview_mine' => $last ? ! $last->isFromGuest() : false,
                    'flow'         => $this->flowFor($a),
                ];
            })->values(),

            'messages' => $this->conversation->feedAcross($ids, $user, $since, $fresh)
                ->map(fn ($m) => [
                    'id'       => $m->id,
                    'attendee' => $m->attendee_id,
                    'room'     => $m->presentation_id,
                    'guest'    => $m->isFromGuest(),
                    'from'     => $m->displayName(),
                    'body'     => $m->body,
                    'at'       => $m->created_at->copy()
                        ->setTimezone(Presentation::bookingTimezone())->format('g:ia'),
                ])->values(),
        ]);
    }

    /**
     * One row per person, not one per video they have reached.
     *
     * A prospect three branches into a flow has three attendee rows. They are
     * still one human with one conversation, so the console is handed the row
     * their thread hangs off, tagged with the row for wherever they are now.
     *
     * @param  \Illuminate\Support\Collection<int, PresentationAttendee>  $attendees
     * @return \Illuminate\Support\Collection<int, PresentationAttendee>
     */
    private function collapseJourneys($attendees)
    {
        [$inFlow, $plain] = $attendees->partition(fn (PresentationAttendee $a) => $a->funnel_participant_id !== null);

        if ($inFlow->isEmpty()) {
            return $attendees;
        }

        $participants = \App\Models\FunnelParticipant::query()
            ->whereIn('id', $inFlow->pluck('funnel_participant_id')->unique())
            ->with('funnel:id,title')
            ->get()
            ->keyBy('id');

        $collapsed = $inFlow
            ->groupBy('funnel_participant_id')
            ->map(function ($rows, $participantId) use ($participants) {
                $participant = $participants->get($participantId);

                // The row their conversation lives on. Falling back to the
                // earliest keeps somebody visible even if the video they
                // entered through has since been pulled out of the flow.
                $anchor = $rows->firstWhere('id', $participant?->thread_attendee_id)
                    ?? $rows->sortBy('id')->first();

                // Carried as relations rather than plain properties: an
                // arbitrary property on an Eloquent model becomes an attribute,
                // and an attribute holding a model is a save() away from a
                // column that does not exist.
                $anchor->setRelation('journeyParticipant', $participant);
                $anchor->setRelation('journeyCurrent', $rows
                    ->firstWhere('presentation_id', $participant?->current_presentation_id) ?? $anchor);

                return $anchor;
            })
            ->values();

        return $plain->concat($collapsed)->values();
    }

    /**
     * Where somebody is in a flow, for the console.
     *
     * Null for anybody who is not in one, which is what tells the page to show
     * an ordinary guest row rather than a journey.
     */
    private function flowFor(PresentationAttendee $attendee): ?array
    {
        $participant = $attendee->relationLoaded('journeyParticipant')
            ? $attendee->getRelation('journeyParticipant')
            : null;

        if (! $participant) {
            return null;
        }

        $last = $participant->choices()->latest('id')->first();

        return [
            'funnel'   => $participant->funnel?->title,
            'step'     => $participant->currentPresentation?->title,
            'step_no'  => $participant->stepNumber(),
            'seen'     => $participant->pathLength(),
            'chose'    => $last?->label,
            'outcome'  => $participant->outcomeLabel(),
        ];
    }

    /**
     * Showings worth having open right now.
     *
     * Running, about to start, or only just finished — a conversation should
     * not vanish the moment a call ends, because that is exactly when the
     * follow-up happens.
     */
    private function activeRooms(?User $user, ?int $openGuestId = null)
    {
        $window = (int) config('presentations.console_window_minutes', 30);

        /*
         * The room a link asked for, whatever its age.
         *
         * Notifications are the main way into a conversation, and one sent last
         * month still has to open. Without this the console would silently drop
         * the guest it was told to open, because their call finished hours ago.
         */
        $wanted = $openGuestId
            ? PresentationAttendee::visibleTo($user)->whereKey($openGuestId)->value('presentation_id')
            : null;

        return Presentation::forMember($user)
            ->whereIn('status', [Presentation::STATUS_LIVE, Presentation::STATUS_SCHEDULED, Presentation::STATUS_ENDED])
            ->where(fn ($q) => $q
                ->when($wanted, fn ($w, $id) => $w->orWhere('id', $id))
                // Always-open shares never leave: somebody could start one at
                // any hour, and the member needs to be able to answer them.
                ->orWhere('format', Presentation::FORMAT_ON_DEMAND)
                ->orWhere('status', Presentation::STATUS_LIVE)
                ->orWhere(fn ($w) => $w->where('status', Presentation::STATUS_SCHEDULED)
                    ->whereBetween('scheduled_at', [now(), now()->addMinutes($window)]))
                ->orWhere(fn ($w) => $w->where('status', Presentation::STATUS_ENDED)
                    ->where('ended_at', '>=', now()->subMinutes($window))))
            ->with(['recording', 'funnel:id,title'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Everyone this member has invited, one row per person.
     *
     * Not per registration: a prospect who came to three calls is one prospect
     * who came three times, and that is what decides what you say to them next.
     */
    public function prospects(Request $request, ProspectReport $report)
    {
        $filters = [
            'search'          => trim((string) $request->get('q')) ?: null,
            'presentation_id' => $request->integer('presentation') ?: null,
        ];

        return view('member.presentations.prospects', [
            'prospects'     => $report->for($request->user(), $filters),
            'totals'        => $report->totals($request->user(), $filters),
            'presentations' => Presentation::forMember($request->user())
                ->orderByDesc('scheduled_at')->limit(50)->get(),
            'filters'       => $filters,
        ]);
    }

    /** Hand a prospect to the CRM so the relationship carries on there. */
    public function toCrm(Request $request, int $attendee, ProspectToCrm $bridge): RedirectResponse
    {
        $guest   = $this->findVisibleAttendee($request, $attendee);
        $contact = $bridge->push($guest, $request->user());

        return back()->with('success', "\"{$guest->name}\" is now in your CRM.");
    }

    /** The form for a member to put a recording on at a time that suits them. */
    public function create(Request $request)
    {
        abort_unless($this->schedulingIsOpen($request->user()), 403);

        return view('member.presentations.create', [
            'recordings' => ScreenRecording::memberSchedulable()->orderBy('title')->get(),
            'remaining'  => $this->remainingAllowance($request->user()),
        ]);
    }

    /**
     * Create a showing that belongs to this member alone.
     *
     * `owner_user_id` is what makes it theirs: it will not appear in anyone
     * else's list, and only their own invite link exists for it.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($this->schedulingIsOpen($user), 403);

        if ($this->remainingAllowance($user) < 1) {
            return back()->withInput()->with('error',
                'You already have '.config('presentations.member_upcoming_limit')
                .' showings coming up. Cancel one to add another.');
        }

        $data = $request->validate([
            'title'        => 'required|string|max:160',
            'description'  => 'nullable|string|max:2000',
            'recording_id' => 'required|integer',
            'format'       => ['nullable', Rule::in(array_keys(Presentation::FORMATS))],
            'scheduled_at' => 'required_if:format,scheduled|nullable|date',
        ], [
            'scheduled_at.required_if' => 'Pick a date and time for a scheduled presentation.',
        ]);

        $onDemand = ($data['format'] ?? Presentation::FORMAT_SCHEDULED) === Presentation::FORMAT_ON_DEMAND;

        // Scoped, not just validated by id: a member may only schedule what an
        // admin has released for the purpose, and the library holds plenty that
        // should never be one click from a prospect.
        $recording = ScreenRecording::memberSchedulable()->find($data['recording_id']);

        if (! $recording) {
            return back()->withInput()
                ->withErrors(['recording_id' => 'That video is not available to schedule.']);
        }

        // An always-open share has no start time to be in the future of; it is
        // open from the moment it exists.
        $startsAt = $onDemand ? now() : Presentation::parseBookingInput($data['scheduled_at']);

        if (! $onDemand && $startsAt->lte(now())) {
            return back()->withInput()
                ->withErrors(['scheduled_at' => 'Pick a time in the future.']);
        }

        $presentation = Presentation::create([
            'title'             => $data['title'],
            'description'       => $data['description'] ?? null,
            'format'            => $onDemand ? Presentation::FORMAT_ON_DEMAND : Presentation::FORMAT_SCHEDULED,
            'recording_id'      => $recording->id,
            'scheduled_at'      => $startsAt,
            // Always-open shares are live from creation: there is no doors-open
            // moment, and the runner has nothing to start or end.
            'status'            => $onDemand ? Presentation::STATUS_LIVE : Presentation::STATUS_SCHEDULED,
            'started_at'        => $onDemand ? $startsAt : null,
            'duration_seconds'  => $recording->duration_seconds,
            'slug'              => Presentation::uniqueSlug(
                $data['title'].($onDemand ? '' : ' '.$startsAt->format('M j'))
            ),
            'owner_user_id'     => $user->id,
            'created_by'        => $user->id,
            'replay_visibility' => Presentation::REPLAY_ATTENDEES,
        ]);

        return redirect()->route('member.presentations.show', $presentation)
            ->with('success', $onDemand
                ? 'Ready. Share your link — it starts from the beginning for whoever opens it.'
                : 'Scheduled. Share your link with your team.');
    }

    /** Cancel your own showing. Guests who registered keep their record. */
    public function destroy(Request $request, Presentation $presentation): RedirectResponse
    {
        abort_unless($presentation->isPersonal() && $presentation->manageableBy($request->user()), 403);

        // An always-open share is permanently "live", so the running check
        // would make it impossible to ever take down.
        if ($presentation->isLive() && ! $presentation->isOnDemand()) {
            return back()->with('error', 'This one is running. Wait for it to finish.');
        }

        $presentation->update(['status' => Presentation::STATUS_CANCELLED]);
        $presentation->delete();

        return redirect()->route('member.presentations.index')
            ->with('success', 'Cancelled.');
    }

    /**
     * The video, for a member watching alongside their guests.
     *
     * Its own route rather than the guest one: a member has an account and no
     * attendee cookie, so the guest check would refuse them. Same rules
     * otherwise — the showing must be theirs to see, and the file is not handed
     * out before it opens.
     */
    public function video(Request $request, Presentation $presentation, RecordingStorage $storage)
    {
        $this->assertReachable($request, $presentation);

        abort_unless(
            $presentation->isLive()
                || $presentation->hasEnded()
                || ($presentation->secondsUntilStart() ?? PHP_INT_MAX)
                    <= (int) config('presentations.preload_seconds'),
            404,
        );

        $recording = $presentation->recording;

        abort_unless($recording?->isReady(), 404);

        if ($url = $storage->temporaryUrl($recording)) {
            return redirect()->away($url);
        }

        $disk = $storage->disk($recording);

        abort_unless($disk->exists($recording->path), 404);

        if ($disk instanceof \Illuminate\Filesystem\FilesystemAdapter) {
            return response()->file($disk->path($recording->path), [
                'Content-Type' => $recording->mime ?: 'video/mp4',
            ]);
        }

        return $disk->response($recording->path);
    }

    /** Clear a guest's unread badge because the member has actually seen it. */
    public function markRead(Request $request, int $attendee): JsonResponse
    {
        $guest = $this->findVisibleAttendee($request, $attendee);

        $this->conversation->markRead($guest, $request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * One guest's thread, keyed by the guest alone.
     *
     * Not scoped to a presentation on purpose: the unified console works across
     * several rooms, and requiring the caller to also name the right showing
     * would mean two ways of doing the same thing. Visibility still decides
     * everything — the query cannot see another member's guest.
     */
    public function thread(Request $request, int $attendee): JsonResponse
    {
        $guest = $this->findVisibleAttendee($request, $attendee);

        return response()->json([
            'attendee' => ['id' => $guest->id, 'name' => $guest->name, 'email' => $guest->email],
            'messages' => $this->conversation->messages($guest)->map(fn ($m) => [
                'id'    => $m->id,
                'guest' => $m->isFromGuest(),
                'from'  => $m->displayName(),
                'body'  => $m->body,
                'at'    => $m->created_at->format('g:ia'),
            ])->values(),
        ]);
    }

    public function reply(Request $request, int $attendee): JsonResponse
    {
        $guest = $this->findVisibleAttendee($request, $attendee);
        $data  = $request->validate(['body' => 'required|string|max:2000']);

        $message = $this->conversation->fromStaff($guest, $request->user(), $data['body']);

        return response()->json([
            'id'    => $message->id,
            'guest' => false,
            'from'  => $message->displayName(),
            'body'  => $message->body,
            'at'    => $message->created_at->format('g:ia'),
        ], 201);
    }

    /** Their own guest list, as a spreadsheet. */
    public function export(Request $request, Presentation $presentation): StreamedResponse
    {
        $attendees = $this->attendeesFor($request, $presentation);
        $filename  = Str::slug($presentation->title).'-guests.csv';

        return response()->streamDownload(function () use ($attendees) {
            $out = fopen('php://output', 'w');
            // Eastern, like everything else people read here — a spreadsheet of
            // UTC timestamps is exactly the confusion this change exists to end.
            $zone = Presentation::bookingTimezone();

            fputcsv($out, ['Name', 'Email', 'Phone', 'Registered (ET)', 'Joined at', 'Watched (min)', 'Clicked join']);

            foreach ($attendees as $a) {
                fputcsv($out, [
                    $a->name,
                    $a->email,
                    $a->phone,
                    $a->registered_at?->copy()->setTimezone($zone)->format('Y-m-d H:i'),
                    $a->hasJoined() ? $a->formattedJoinOffset() : '',
                    round($a->watch_seconds / 60, 1),
                    $a->cta_clicked_at ? 'yes' : '',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Resolve a guest this user is actually allowed to touch.
     *
     * Goes through the scope rather than findOrFail + a check, so there is no
     * path where the row is loaded first and authorised second — the query
     * itself cannot see another member's guest.
     */
    private function findVisibleAttendee(Request $request, int $id)
    {
        return PresentationAttendee::query()
            ->visibleTo($request->user())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * Refuse another member's personal room outright.
     *
     * Its guest list is private for the same reason every guest list is, and a
     * 404 rather than a 403 keeps the fact of its existence to itself.
     */
    private function assertReachable(Request $request, Presentation $presentation): void
    {
        $user = $request->user();

        abort_if(
            $presentation->isPersonal() && ! $presentation->manageableBy($user),
            404,
        );
    }

    private function schedulingIsOpen(?User $user): bool
    {
        return $user !== null
            && (bool) config('presentations.members_can_schedule')
            && filled($user->referral_code);
    }

    /** How many more upcoming showings this member may create. */
    private function remainingAllowance(User $user): int
    {
        if ($user->isAdmin()) {
            return PHP_INT_MAX;
        }

        $used = Presentation::where('owner_user_id', $user->id)
            ->where('scheduled_at', '>=', now())
            ->count();

        return max(0, (int) config('presentations.member_upcoming_limit') - $used);
    }

    /**
     * Guests this user may see, for this showing.
     *
     * A member's own; an admin's is everyone. `setRelation` avoids an N+1 on
     * `isWatching()`, which needs the presentation to know whether it is live.
     */
    private function attendeesFor(Request $request, Presentation $presentation)
    {
        return $presentation->attendees()
            ->visibleTo($request->user())
            ->orderByDesc('first_joined_at')
            ->orderBy('name')
            ->get()
            ->each->setRelation('presentation', $presentation);
    }
}
