<?php

namespace App\Http\Controllers;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationCue;
use App\Models\User;
use App\Services\Presentations\AttendeeRegistrar;
use App\Services\Presentations\Conversation;
use App\Services\Presentations\ConversionTracker;
use App\Services\Presentations\FunnelJourney;
use App\Support\PresentationCta;
use App\Services\ScreenRecording\RecordingStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The guest side: register, watch, and join through whoever invited you.
 *
 * Entirely public — a guest has no account and never gets one here. Their
 * identity is a token in a cookie, issued at registration.
 */
class PresentationWatchController extends Controller
{
    public function __construct(
        private readonly AttendeeRegistrar $registrar,
        private readonly Conversation $conversation,
        private readonly ConversionTracker $conversions,
        private readonly FunnelJourney $journey,
    ) {
    }

    /**
     * The room, or the registration form if they have not registered yet.
     *
     * `$code` is the inviting member's referral code. It is the only thing that
     * attributes a guest, so it is carried through registration in a hidden
     * field rather than trusted to survive in the session.
     */
    public function show(Request $request, Presentation $presentation, ?string $code = null)
    {
        abort_if($presentation->isCancelled(), 404);

        $host     = $this->resolveHost($code);
        $attendee = $this->currentAttendee($request, $presentation);

        /*
         * Every guest must arrive through somebody's invite code.
         *
         * A guest with no inviting member is an orphan: there is no way to work
         * out later who they belong to, and that becomes a commission argument
         * nobody can settle. Even head office sponsors through a code — the top
         * position has one — so there is no legitimate case for a bare link.
         *
         * A returning guest is let through on their cookie, because they were
         * attributed when they registered.
         */
        if (! $attendee && ! $host) {
            return response()->view('presentations.invite-required', [
                'presentation' => $presentation,
                'unknownCode'  => filled($code),
            ], 404);
        }

        if (! $attendee) {
            return view('presentations.register', [
                'presentation' => $presentation,
                'host'         => $host,
                'code'         => $code,
            ]);
        }

        // Registered before the doors opened, coming back to watch.
        if ($presentation->isLive()
            && $this->registrar->markJoined($attendee, $presentation)
            && $this->isFirstStopOfJourney($attendee)) {
            $this->conversation->notifyHostOfArrival($attendee);
        }

        $roomHost = $attendee->host ?? $host;
        $cta      = PresentationCta::for($presentation, $roomHost);

        return view('presentations.room', [
            'presentation' => $presentation,
            'attendee'     => $attendee,
            'host'         => $roomHost,
            'code'         => $roomHost?->referral_code ?? $code,
            'cta'          => $cta,
            // Carried on the link so a signup traces back here whatever address
            // it uses — people give a throwaway one until they have decided.
            'ctaUrl'       => $cta->isVisible()
                ? $this->tracked($cta->url, $attendee)
                : null,
            // The choices placed on this video, each with the moment it appears.
            'cues'         => $this->cuesFor($presentation, $attendee, $roomHost),
        ]);
    }

    public function register(Request $request, Presentation $presentation): RedirectResponse
    {
        abort_if($presentation->isCancelled(), 404);

        if (! $presentation->is_open) {
            return back()->with('error', 'Registration for this presentation is closed.');
        }

        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'email' => 'required|email:rfc|max:190',
            'phone' => ($presentation->collect_phone ? 'required' : 'nullable').'|string|max:40',
            'code'  => 'required|string|max:40',
        ], [
            'name.required'  => 'Please tell us your name.',
            'email.required' => 'Please give an email address so we can send you the replay.',
            'code.required'  => 'This link is missing its invitation code.',
        ]);

        $host = $this->resolveHost($data['code']);

        // The second half of the same rule as show(): no host, no registration.
        // Checked again here because the form posts the code back as a field,
        // and a field can be edited.
        if (! $host) {
            return back()->withInput()->with(
                'error',
                'That invitation link is not valid. Ask the person who invited you for a new one.'
            );
        }

        $attendee = $this->registrar->register($presentation, $data, $host, $request);

        // Long-lived so a guest registering in the morning is still known when
        // the presentation starts that evening.
        Cookie::queue(
            $this->cookieName($presentation),
            $attendee->token,
            60 * 24 * 30,
            httpOnly: true,
        );

        return redirect()->route('presentations.watch', array_filter([
            'presentation' => $presentation->slug,
            'code'         => $data['code'] ?? null,
        ]));
    }

    /**
     * Keeps the attendee's presence and progress current.
     *
     * Called every 20 seconds by the player. Deliberately lean: no view, no
     * eager loads, and it answers with the room's authoritative position so the
     * client can correct its own drift from the same reply.
     */
    public function heartbeat(Request $request, Presentation $presentation): JsonResponse
    {
        $attendee = $this->currentAttendee($request, $presentation);

        if (! $attendee) {
            return response()->json(['message' => 'Not registered.'], 403);
        }

        $data = $request->validate([
            'position' => 'nullable|integer|min:0',
        ]);

        if ($presentation->isLive() && $this->registrar->markJoined($attendee, $presentation)) {
            // Inside a funnel only the first arrival is announced. Every branch
            // after it produces a choice notification instead, which says
            // something a member can act on rather than "they are still here".
            if ($this->isFirstStopOfJourney($attendee)) {
                $this->conversation->notifyHostOfArrival($attendee);
            }
        }

        $this->registrar->heartbeat($attendee, $presentation, $data['position'] ?? null);

        // Keep the journey's own presence current, so the console can say where
        // in a flow somebody is without reading every attendee row they own.
        if ($participant = $attendee->funnelParticipant) {
            $this->journey->touch($participant, $presentation);
        }

        return response()->json($this->clock($presentation, $attendee->refresh()));
    }

    /** The clock the player synchronises against. Server time is the only time. */
    public function state(Request $request, Presentation $presentation): JsonResponse
    {
        return response()->json(
            $this->clock($presentation, $this->currentAttendee($request, $presentation))
        );
    }

    /**
     * The guest clicked "see how to get started".
     *
     * Records the intent against the attendee so their inviting member can see
     * who acted, and the page opens the destination itself; this only logs it.
     */
    public function cta(Request $request, Presentation $presentation): JsonResponse
    {
        $attendee = $this->currentAttendee($request, $presentation);

        if (! $attendee) {
            return response()->json(['message' => 'Not registered.'], 403);
        }

        $this->recordCtaClick($attendee);

        return response()->json(['ok' => true]);
    }

    /**
     * Stamp that this guest acted, once.
     *
     * Once, because the interesting fact is that they did — clicking twice is
     * not twice the intent, and the member's list would otherwise reorder
     * itself every time somebody went back for another look. This project has
     * no invite-lead table; the host moves a prospect into their CRM from the
     * prospects report (ProspectToCrm) instead.
     */
    private function recordCtaClick(PresentationAttendee $attendee): void
    {
        if ($attendee->cta_clicked_at !== null) {
            return;
        }

        $attendee->forceFill(['cta_clicked_at' => now()])->save();
    }

    /**
     * The video itself.
     *
     * Deliberately its own route rather than reusing the recording's public
     * stream: the recording ACL is about members and lessons, and a guest is
     * neither. It also lets us refuse the file until the showing has actually
     * started — otherwise anyone registered could pull the URL the day before
     * and watch the whole thing early, which defeats the point of a scheduled
     * showing.
     */
    public function video(Request $request, Presentation $presentation, RecordingStorage $storage)
    {
        $attendee = $this->currentAttendee($request, $presentation);

        abort_unless($attendee !== null, 403, 'Register first.');
        abort_unless($this->videoIsAvailable($presentation), 404, 'This presentation has not started.');

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

    /** The guest's own thread. Never anyone else's — they have no way to name one. */
    public function messages(Request $request, Presentation $presentation): JsonResponse
    {
        $attendee = $this->currentAttendee($request, $presentation);

        if (! $attendee) {
            return response()->json(['message' => 'Not registered.'], 403);
        }

        $after = $request->integer('after') ?: null;

        // Inside a funnel the thread lives on the room they entered through,
        // so moving to the next video does not start a new conversation.
        return response()->json([
            'messages' => $this->conversation->messages($attendee->conversationAnchor(), $after)
                ->map(fn ($m) => [
                    'id'   => $m->id,
                    'mine' => $m->isFromGuest(),
                    'from' => $m->displayName(),
                    'body' => $m->body,
                    'at'   => $m->created_at->format('g:ia'),
                ])->values(),
            'announcements' => $presentation->announcements()
                ->when($after === null, fn ($q) => $q)
                ->get()
                ->map(fn ($a) => ['id' => $a->id, 'body' => $a->body])
                ->values(),
        ]);
    }

    public function sendMessage(Request $request, Presentation $presentation): JsonResponse
    {
        $attendee = $this->currentAttendee($request, $presentation);

        if (! $attendee) {
            return response()->json(['message' => 'Not registered.'], 403);
        }

        $data = $request->validate(['body' => 'required|string|max:2000']);

        $message = $this->conversation->fromGuest($attendee->conversationAnchor(), $data['body']);

        return response()->json([
            'id'   => $message->id,
            'mine' => true,
            'from' => $attendee->name,
            'body' => $message->body,
            'at'   => $message->created_at->format('g:ia'),
        ], 201);
    }

    /**
     * The guest picked one of the choices on screen.
     *
     * Both kinds land here, because to the person watching they were the same
     * thing: a button that appeared when it became relevant. A branch answers
     * with the next video's URL; a call to action answers with its destination,
     * carrying the conversion token so a sign-up under a different address
     * still traces back to this moment.
     */
    public function choose(Request $request, Presentation $presentation): JsonResponse
    {
        $attendee = $this->currentAttendee($request, $presentation);

        if (! $attendee) {
            return response()->json(['message' => 'Not registered.'], 403);
        }

        $data = $request->validate([
            'cue'      => 'required|integer',
            'position' => 'nullable|integer|min:0',
        ]);

        // Scoped to this showing on purpose: a cue id from another video is not
        // a choice anybody was offered here.
        $cue = $presentation->cues()->whereKey($data['cue'])->first();

        if (! $cue || ! $cue->isPlayable()) {
            return response()->json(['message' => 'That option is no longer available.'], 404);
        }

        $participant = $attendee->funnelParticipant;

        if (! $participant) {
            // A cue on a video outside a funnel still works — it is an ordinary
            // call to action that happens to be timed. There is just no journey
            // to move along, so nothing branches.
            if ($cue->isBranch()) {
                return response()->json(['message' => 'That option is no longer available.'], 404);
            }

            $cta = $cue->resolveCta($attendee->host);
            $this->recordCtaClick($attendee);

            return response()->json([
                'kind' => PresentationCue::KIND_CTA,
                'url'  => $cta?->url ? $this->tracked($cta->url, $attendee) : null,
            ]);
        }

        $result = $this->journey->choose($participant, $cue, $data['position'] ?? null, $request);

        if ($cue->isBranch()) {
            $next = $result['next'];

            if (! $next) {
                return response()->json(['message' => 'That option is no longer available.'], 404);
            }

            // Their attendee row on the next video already exists, so hand its
            // cookie over now rather than making the room's first request a
            // special case.
            $nextAttendee = $participant->attendees()
                ->where('presentation_id', $next->id)->first();

            if ($nextAttendee) {
                Cookie::queue(
                    $this->cookieName($next),
                    $nextAttendee->token,
                    60 * 24 * 60,
                    httpOnly: true,
                );
            }

            return response()->json([
                'kind' => PresentationCue::KIND_BRANCH,
                'url'  => route('presentations.watch', array_filter([
                    'presentation' => $next->slug,
                    'code'         => $participant->host?->referral_code,
                ])),
            ]);
        }

        $this->recordCtaClick($attendee);

        return response()->json([
            'kind' => PresentationCue::KIND_CTA,
            'url'  => $result['url'] ? $this->tracked($result['url'], $attendee) : null,
        ]);
    }

    /**
     * The choices on this video, ready to render.
     *
     * Resolved server-side so the page never has to know how a destination is
     * built, and so a cue whose target was deleted simply is not there.
     */
    private function cuesFor(
        Presentation $presentation,
        PresentationAttendee $attendee,
        ?User $host,
    ): \Illuminate\Support\Collection {
        return $presentation->playableCues()->map(function (PresentationCue $cue) use ($attendee, $host) {
            $cta = $cue->resolveCta($host);

            return [
                'id'       => $cue->id,
                'kind'     => $cue->kind,
                'label'    => $cue->buttonLabel(),
                'headline' => $cue->headline(),
                'note'     => $cue->subtext(),
                'at'       => $cue->starts_at_seconds,
                'until'    => $cue->ends_at_seconds,
                // A branch always navigates in this tab — it is the same
                // journey continuing. A call to action does whichever its
                // item was set to: beside the video for something they come
                // back from, or straight there when it is the next step.
                'external' => ! $cue->isBranch() && ($cta?->opensInNewWindow() ?? true),
                'url'      => $cue->isBranch()
                    ? null
                    : ($cta?->url ? $this->tracked($cta->url, $attendee) : null),
            ];
        })->filter(fn (array $c) => $c['kind'] === PresentationCue::KIND_BRANCH || filled($c['url']))
          ->values();
    }

    /**
     * Is this the guest's first stop, rather than a later step of a flow?
     *
     * True for anybody outside a funnel, and for the video a participant
     * entered through. False for every branch after that.
     */
    private function isFirstStopOfJourney(PresentationAttendee $attendee): bool
    {
        $participant = $attendee->funnelParticipant;

        return $participant === null || $participant->thread_attendee_id === $attendee->id;
    }

    /**
     * Attach the conversion token to a destination.
     *
     * The token, not the email address, is what links a sign-up back to the
     * person who watched: people give a throwaway address until they have
     * decided, and matching on email alone would miss most of the conversions
     * that matter.
     */
    private function tracked(string $url, PresentationAttendee $attendee): string
    {
        return $url
            .(str_contains($url, '?') ? '&' : '?')
            .ConversionTracker::PARAM.'='.$this->conversions->tokenFor($attendee);
    }

    /**
     * Whether the file may be served yet.
     *
     * While running, obviously. Afterwards only if the replay is meant to be
     * seen — a finished showing with replay off must stop handing out the video.
     */
    private function videoIsAvailable(Presentation $presentation): bool
    {
        // An always-open share is exactly that: there is no doors-open moment
        // to withhold it until.
        if ($presentation->isOnDemand()) {
            return ! $presentation->hasEnded() || $presentation->replayIsOpenTo(null);
        }

        if ($presentation->isLive()) {
            return true;
        }

        // A short pre-roll window so a waiting guest can buffer the opening and
        // playback starts cleanly instead of spinning. See config for the
        // trade being made.
        if ($presentation->isScheduled()) {
            $opensIn = $presentation->secondsUntilStart();

            return $opensIn !== null
                && $opensIn <= (int) config('presentations.preload_seconds');
        }

        return $presentation->hasEnded() && $presentation->replayIsOpenTo(null);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function clock(Presentation $presentation, ?PresentationAttendee $attendee = null): array
    {
        return [
            // Where this viewer got to, so they resume rather than restart, and
            // how far they have earned the right to skip to.
            'resume_at' => $presentation->isOnDemand() ? (int) ($attendee?->position_seconds ?? 0) : null,
            'furthest'  => $presentation->isOnDemand() ? (int) ($attendee?->furthest_seconds ?? 0) : null,
            'status'     => $presentation->status,
            'server_now' => now()->toIso8601String(),
            'started_at' => $presentation->started_at?->toIso8601String(),
            'starts_at'  => $presentation->scheduled_at->toIso8601String(),
            'format'     => $presentation->format,
            'duration'   => $presentation->duration_seconds,
            'offset'     => $presentation->currentOffset(),
            // Lets the page start buffering before the doors open.
            'can_preload' => $this->videoIsAvailable($presentation),
        ];
    }

    private function cookieName(Presentation $presentation): string
    {
        return 'pres_'.$presentation->id;
    }

    private function currentAttendee(Request $request, Presentation $presentation): ?PresentationAttendee
    {
        $token = $request->cookie($this->cookieName($presentation));

        if (! $token) {
            return null;
        }

        return $presentation->attendees()->where('token', $token)->first();
    }

    /**
     * The member whose link brought this guest here.
     *
     * Any real user's code counts — admins and the top position recruit too.
     * An unknown code resolves to nobody, and the caller turns that away rather
     * than quietly creating a guest nobody owns.
     */
    private function resolveHost(?string $code): ?User
    {
        if (blank($code)) {
            return null;
        }

        return User::where('referral_code', $code)->first();
    }
}
