<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationAnnouncement;
use App\Models\RecordingChapter;
use App\Models\ScreenRecording;
use App\Services\Presentations\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scheduling showings and watching the room.
 */
class PresentationController extends Controller
{
    public function __construct(private readonly Conversation $conversation)
    {
    }

    public function index()
    {
        return view('admin.presentations.index', [
            'upcoming' => Presentation::upcoming()->with(['recording', 'author'])->withCount('attendees')->get(),
            'past'     => Presentation::past()->with(['recording', 'author'])->withCount('attendees')->paginate(20),
        ]);
    }

    public function create()
    {
        return view('admin.presentations.form', [
            'presentation' => new Presentation([
                'scheduled_at' => now()->addDay()->startOfHour(),
            ]),
            'recordings'   => $this->selectableRecordings(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $recording = ScreenRecording::ready()->findOrFail($data['recording_id']);

        if (! $recording->duration_seconds) {
            return back()->withInput()->withErrors([
                'recording_id' => 'That recording has no known length, so it cannot be scheduled. Re-save it first.',
            ]);
        }

        $onDemand = $data['format'] === Presentation::FORMAT_ON_DEMAND;

        $presentation = Presentation::create([
            ...$data,
            // The form has no timezone control: what was typed is Eastern. An
            // always-open share has no start time, so it is open from now.
            'scheduled_at' => $onDemand
                ? now()
                : Presentation::parseBookingInput($data['scheduled_at']),
            // Nothing to wait for and nothing for the runner to start.
            'status'     => $onDemand ? Presentation::STATUS_LIVE : Presentation::STATUS_SCHEDULED,
            'started_at' => $onDemand ? now() : null,
            'slug'       => Presentation::uniqueSlug($data['title']),
            'created_by' => $request->user()->id,
            // Copied, not read live: trimming the recording later must not
            // silently move when an already-scheduled showing ends.
            'duration_seconds' => $recording->duration_seconds,
        ]);

        return redirect()->route('admin.presentations.show', $presentation)
            ->with('success', 'Presentation scheduled.');
    }

    public function show(Presentation $presentation)
    {
        $presentation->load(['recording', 'author', 'announcements.author']);

        return view('admin.presentations.show', [
            'presentation' => $presentation,
            // Admins see every guest, including those with no inviting member.
            'attendees'    => $presentation->attendees()
                ->with('host')
                ->orderByDesc('first_joined_at')
                ->orderBy('name')
                ->get()
                ->each->setRelation('presentation', $presentation),
            'claims'       => $presentation->claims()->with(['attendee', 'claimedBy'])->get(),
            'byHost'       => $presentation->attendees()
                ->selectRaw('host_user_id, count(*) as total')
                ->groupBy('host_user_id')
                ->with('host')
                ->get(),
            'unread'   => $this->conversation->unreadCounts($presentation, request()->user()),
            'chapters' => $presentation->recording?->chapters ?? collect(),
        ]);
    }

    /** Every prospect across every showing, with who invited them. */
    public function prospects(Request $request, \App\Services\Presentations\ProspectReport $report)
    {
        $filters = [
            'search'          => trim((string) $request->get('q')) ?: null,
            'presentation_id' => $request->integer('presentation') ?: null,
            'host_id'         => $request->integer('host') ?: null,
        ];

        return view('admin.presentations.prospects', [
            'prospects'     => $report->for($request->user(), $filters),
            'totals'        => $report->totals($request->user(), $filters),
            'presentations' => Presentation::orderByDesc('scheduled_at')->limit(50)->get(),
            'hosts'         => \App\Models\User::whereIn(
                'id',
                \App\Models\PresentationAttendee::whereNotNull('host_user_id')->distinct()->pluck('host_user_id')
            )->orderBy('name')->get(),
            'filters'       => $filters,
        ]);
    }

    public function edit(Presentation $presentation)
    {
        return view('admin.presentations.form', [
            'presentation' => $presentation,
            'recordings'   => $this->selectableRecordings(),
        ]);
    }

    public function update(Request $request, Presentation $presentation): RedirectResponse
    {
        $data = $this->validated($request, $presentation);

        // Changing what kind of showing this is mid-flight would move every
        // viewer's playhead, so it is fixed once anyone can be watching.
        if ($data['format'] !== $presentation->format && ! $presentation->isScheduled()) {
            $data['format'] = $presentation->format;
        }

        if ($data['format'] === Presentation::FORMAT_ON_DEMAND) {
            // Turning a booking into an always-open share opens it there and
            // then — otherwise it would sit waiting for a time nobody is
            // watching for any more.
            $data['scheduled_at'] = $presentation->isOnDemand() ? $presentation->scheduled_at : now();
            $data['status']       = Presentation::STATUS_LIVE;
            $data['started_at']   = $presentation->started_at ?? $data['scheduled_at'];
        } else {
            $data['scheduled_at'] = Presentation::parseBookingInput($data['scheduled_at']);
        }

        // Moving the start of a showing that is already running would jump
        // every viewer to a different point at once.
        if ($presentation->isLive() && ! $presentation->isOnDemand()
            && ! $data['scheduled_at']->equalTo($presentation->scheduled_at)) {
            return back()->withInput()->withErrors([
                'scheduled_at' => 'This presentation is running. Its start time can no longer be changed.',
            ]);
        }

        if ($presentation->recording_id !== (int) $data['recording_id']) {
            $recording = ScreenRecording::ready()->findOrFail($data['recording_id']);
            $data['duration_seconds'] = $recording->duration_seconds;
        }

        $presentation->update($data);

        return redirect()->route('admin.presentations.show', $presentation)
            ->with('success', 'Presentation updated.');
    }

    public function start(Presentation $presentation): RedirectResponse
    {
        if (! $presentation->isScheduled()) {
            return back()->with('error', 'This presentation is not waiting to start.');
        }

        $presentation->start();

        return back()->with('success', 'Started.');
    }

    public function end(Presentation $presentation): RedirectResponse
    {
        $presentation->end();

        return back()->with('success', 'Ended.');
    }

    public function destroy(Presentation $presentation): RedirectResponse
    {
        if ($presentation->isLive()) {
            return back()->with('error', 'End it before cancelling it.');
        }

        // Soft delete: the attendance log is the members' record of who they
        // invited, and it should not vanish because a showing was tidied away.
        $presentation->update(['status' => Presentation::STATUS_CANCELLED]);
        $presentation->delete();

        return redirect()->route('admin.presentations.index')->with('success', 'Presentation cancelled.');
    }

    /** A one-way note to everyone watching. */
    public function announce(Request $request, Presentation $presentation): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:1000']);

        PresentationAnnouncement::create([
            'presentation_id' => $presentation->id,
            'user_id'         => $request->user()->id,
            'body'            => $data['body'],
        ]);

        return back()->with('success', 'Sent to everyone watching.');
    }

    /**
     * Replace the chapter list for the underlying recording.
     *
     * Stored against the recording, not the showing, so scheduling the same
     * talk again reuses them. Rewritten wholesale because the editor is a
     * textarea — simpler to reason about than diffing rows, and there are only
     * ever a handful.
     */
    public function saveChapters(Request $request, Presentation $presentation): RedirectResponse
    {
        $recording = $presentation->recording;

        if (! $recording) {
            return back()->with('error', 'This presentation has no recording.');
        }

        $data = $request->validate(['chapters' => 'nullable|string|max:4000']);

        $parsed = collect(preg_split('/\r?\n/', (string) ($data['chapters'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(function (string $line) {
                // "14:02 The Compensation Plan" or "842 The Compensation Plan"
                if (! preg_match('/^(\d+):([0-5]?\d)\s+(.+)$/', $line, $m)
                    && ! preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                    return null;
                }

                return count($m) === 4
                    ? ['starts_at_seconds' => ((int) $m[1] * 60) + (int) $m[2], 'label' => trim($m[3])]
                    : ['starts_at_seconds' => (int) $m[1], 'label' => trim($m[2])];
            })
            ->filter()
            ->values();

        $recording->chapters()->delete();

        foreach ($parsed as $chapter) {
            $recording->chapters()->create($chapter);
        }

        return back()->with('success', $parsed->count().' chapters saved for this recording.');
    }


    // ── Internals ─────────────────────────────────────────────────────────────

    private function validated(Request $request, ?Presentation $presentation = null): array
    {
        $data = $request->validate([
            'title'             => 'required|string|max:160',
            'description'       => 'nullable|string|max:2000',
            'recording_id'      => 'required|exists:screen_recordings,id',
            'format'            => ['nullable', Rule::in(array_keys(Presentation::FORMATS))],
            // An always-open share has no start time to ask for.
            'scheduled_at'      => 'required_if:format,scheduled|nullable|date',
            'replay_visibility' => ['required', Rule::in(array_keys(Presentation::REPLAY_OPTIONS))],
            // Optional: absent means "whatever phase we are in", not an error.
            'cta_type'          => ['nullable', Rule::in(array_keys(\App\Support\PresentationCta::TYPES))],
            'cta_headline'      => 'nullable|string|max:160',
            'cta_label'         => 'nullable|string|max:60',
            'cta_note'          => 'nullable|string|max:300',
            'cta_url'           => 'nullable|url|max:500|required_if:cta_type,custom,schedule_call',
            'cta_opens_in'      => ['nullable', Rule::in(array_keys(\App\Support\PresentationCta::OPENS))],
            'collect_phone'     => 'boolean',
            'is_open'           => 'boolean',
        ], [
            'scheduled_at.required_if' => 'Pick a date and time for a scheduled presentation.',
        ]);

        $data['collect_phone'] = $request->boolean('collect_phone');
        $data['is_open']       = $request->boolean('is_open');
        $data['format']        = $data['format'] ?? Presentation::FORMAT_SCHEDULED;
        $data['cta_type']      = $data['cta_type']
            ?? config('presentations.default_cta', \App\Support\PresentationCta::JOIN);
        $data['cta_opens_in']  = $data['cta_opens_in'] ?? \App\Support\PresentationCta::OPENS_NEW;

        return $data;
    }

    /** Only recordings that actually have a playable file and a known length. */
    private function selectableRecordings()
    {
        return ScreenRecording::ready()
            ->whereNotNull('duration_seconds')
            ->orderBy('title')
            ->get();
    }
}
