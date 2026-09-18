<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CtaItem;
use App\Models\Presentation;
use App\Models\PresentationCue;
use App\Models\PresentationFunnel;
use App\Models\ScreenRecording;
use App\Services\Presentations\FunnelReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Building a flow.
 *
 * Three things to arrange and nothing else: which videos are in it, which one
 * people start on, and what each video offers. A step is created here as an
 * ordinary always-open presentation, because that is what it is — everything
 * downstream then treats it as one.
 */
class PresentationFunnelController extends Controller
{
    public function index()
    {
        return view('admin.funnels.index', [
            'funnels' => PresentationFunnel::withCount(['steps', 'participants'])
                ->with('entry:id,title')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function create()
    {
        return view('admin.funnels.form', ['funnel' => new PresentationFunnel(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $funnel = PresentationFunnel::create($this->validated($request) + [
            'slug'       => PresentationFunnel::uniqueSlug($request->string('title')),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.funnels.show', $funnel)
            ->with('success', 'Now add the videos people will see.');
    }

    /**
     * The builder.
     *
     * Everything about the flow on one page — the steps, and the choices each
     * step offers — because the whole point of a funnel is what connects to
     * what, and that is impossible to hold in your head across three screens.
     */
    public function show(PresentationFunnel $funnel)
    {
        $funnel->load(['steps.cues.ctaItem', 'steps.cues.nextPresentation', 'steps.recording']);

        return view('admin.funnels.show', [
            'funnel'     => $funnel,
            'ctaItems'   => CtaItem::active()->orderBy('name')->get(),
            'recordings' => ScreenRecording::ready()
                ->whereNotNull('duration_seconds')
                ->orderBy('title')->get(),
            'stats'      => [
                'started'  => $funnel->participants()->count(),
                'finished' => $funnel->participants()->whereNotNull('outcome')->count(),
                'choices'  => $funnel->choices()->count(),
            ],
        ]);
    }

    /**
     * Who went through, and what they picked.
     *
     * Admins see everyone. The same page serves a member, scoped to their own
     * prospects — the report is the same question whoever is asking, and two
     * implementations would be two places for the isolation to be forgotten.
     */
    public function prospects(Request $request, PresentationFunnel $funnel, FunnelReport $report)
    {
        return view('admin.funnels.prospects', [
            'funnel'  => $funnel,
            'people'  => $report->people($funnel, $request->user()),
            'choices' => $report->choices($funnel, $request->user()),
            'totals'  => $report->totals($funnel, $request->user()),
        ]);
    }

    public function edit(PresentationFunnel $funnel)
    {
        return view('admin.funnels.form', ['funnel' => $funnel]);
    }

    public function update(Request $request, PresentationFunnel $funnel): RedirectResponse
    {
        $funnel->update($this->validated($request));

        return redirect()->route('admin.funnels.show', $funnel)->with('success', 'Saved.');
    }

    public function destroy(PresentationFunnel $funnel): RedirectResponse
    {
        if ($funnel->participants()->exists()) {
            return back()->with('error',
                'People have been through this flow. Switch it off instead — deleting it would take their history with it.');
        }

        $funnel->delete();

        return redirect()->route('admin.funnels.index')->with('success', 'Deleted.');
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    /**
     * Add a video to the flow.
     *
     * Created as an always-open presentation: a funnel step has no start time
     * because whoever arrives at it does so whenever they get there.
     */
    public function addStep(Request $request, PresentationFunnel $funnel): RedirectResponse
    {
        $data = $request->validate([
            'recording_id' => 'required|exists:screen_recordings,id',
            'title'        => 'nullable|string|max:160',
        ]);

        $recording = ScreenRecording::ready()->findOrFail($data['recording_id']);

        if (! $recording->duration_seconds) {
            return back()->withErrors([
                'recording_id' => 'That recording has no known length, so choices could not be timed on it.',
            ]);
        }

        $title = $data['title'] ?? null ?: $recording->title;

        $step = Presentation::create([
            'title'             => $title,
            'slug'              => Presentation::uniqueSlug($title),
            'format'            => Presentation::FORMAT_ON_DEMAND,
            'recording_id'      => $recording->id,
            'scheduled_at'      => now(),
            'started_at'        => now(),
            'status'            => Presentation::STATUS_LIVE,
            'duration_seconds'  => $recording->duration_seconds,
            'funnel_id'         => $funnel->id,
            'funnel_order'      => (int) $funnel->steps()->max('funnel_order') + 1,
            'replay_visibility' => Presentation::REPLAY_ATTENDEES,
            'created_by'        => $request->user()->id,
        ]);

        // The first video added is the obvious place to start, and saying so
        // saves an admin a step they would otherwise always take.
        if (! $funnel->entry_presentation_id) {
            $funnel->forceFill(['entry_presentation_id' => $step->id])->save();
        }

        return back()->with('success', "\"{$step->title}\" added.");
    }

    public function setEntry(PresentationFunnel $funnel, Presentation $presentation): RedirectResponse
    {
        abort_unless($presentation->funnel_id === $funnel->id, 404);

        $funnel->forceFill(['entry_presentation_id' => $presentation->id])->save();

        return back()->with('success', "Everyone now starts on \"{$presentation->title}\".");
    }

    /**
     * Take a video out of the flow.
     *
     * The presentation itself survives — people have watched it and said things
     * in it — it simply stops being part of this journey. Cues pointing at it
     * stop being offered on their own, because a branch with nothing to branch
     * to is never playable.
     */
    public function removeStep(PresentationFunnel $funnel, Presentation $presentation): RedirectResponse
    {
        abort_unless($presentation->funnel_id === $funnel->id, 404);

        $presentation->forceFill(['funnel_id' => null, 'funnel_order' => 0])->save();

        if ($funnel->entry_presentation_id === $presentation->id) {
            $funnel->forceFill([
                'entry_presentation_id' => $funnel->steps()->value('id'),
            ])->save();
        }

        return back()->with('success', "\"{$presentation->title}\" removed from the flow.");
    }

    // ── Choices on a video ────────────────────────────────────────────────────

    public function addCue(Request $request, Presentation $presentation): RedirectResponse
    {
        $data = $request->validate([
            'kind'                 => ['required', Rule::in(array_keys(PresentationCue::KINDS))],
            'cta_item_id'          => 'nullable|exists:cta_items,id|required_if:kind,cta',
            'next_presentation_id' => 'nullable|exists:presentations,id|required_if:kind,branch',
            'label'                => 'nullable|string|max:80',
            'note'                 => 'nullable|string|max:300',
            'starts_at'            => 'required|string|max:12',
            'ends_at'              => 'nullable|string|max:12',
        ], [
            'cta_item_id.required_if'          => 'Pick which call to action to show.',
            'next_presentation_id.required_if' => 'Pick which video this leads to.',
        ]);

        $startsAt = $this->seconds($data['starts_at']);
        $endsAt   = filled($data['ends_at'] ?? null) ? $this->seconds($data['ends_at']) : null;

        if ($startsAt === null) {
            return back()->withInput()->withErrors(['starts_at' => 'Use a time like 4:30 or 90.']);
        }

        // A choice offered after the video has finished is a choice nobody is
        // ever shown.
        if ($presentation->duration_seconds && $startsAt >= $presentation->duration_seconds) {
            return back()->withInput()->withErrors([
                'starts_at' => 'That is past the end of the video ('
                    .$presentation->formattedDuration().').',
            ]);
        }

        if ($endsAt !== null && $endsAt <= $startsAt) {
            return back()->withInput()->withErrors(['ends_at' => 'It has to disappear after it appears.']);
        }

        $branch = $data['kind'] === PresentationCue::KIND_BRANCH;

        if ($branch && (int) $data['next_presentation_id'] === $presentation->id) {
            return back()->withInput()->withErrors([
                'next_presentation_id' => 'A video cannot lead to itself.',
            ]);
        }

        $presentation->cues()->create([
            'kind'                 => $data['kind'],
            'cta_item_id'          => $branch ? null : $data['cta_item_id'],
            'next_presentation_id' => $branch ? $data['next_presentation_id'] : null,
            'label'                => $data['label'] ?? null,
            'note'                 => $data['note'] ?? null,
            'starts_at_seconds'    => $startsAt,
            'ends_at_seconds'      => $endsAt,
            'sort_order'           => (int) $presentation->cues()->max('sort_order') + 1,
            'is_active'            => true,
        ]);

        return back()->with('success', 'Added.');
    }

    public function toggleCue(PresentationCue $cue): RedirectResponse
    {
        $cue->update(['is_active' => ! $cue->is_active]);

        return back()->with('success', $cue->is_active ? 'Showing again.' : 'Hidden.');
    }

    public function destroyCue(PresentationCue $cue): RedirectResponse
    {
        $cue->delete();

        return back()->with('success', 'Removed.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:160',
            'description' => 'nullable|string|max:2000',
        ]) + [
            'is_active'        => $request->boolean('is_active'),
            'member_shareable' => $request->boolean('member_shareable'),
        ];
    }

    /**
     * Read "4:30", "1:02:10" or "270" as a number of seconds.
     *
     * Admins think in the clock they see on the video, not in seconds, and
     * making them convert is how a cue ends up ninety seconds off.
     */
    private function seconds(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^\d+(:[0-5]?\d){0,2}$/', $value)) {
            return null;
        }

        $parts = array_reverse(array_map('intval', explode(':', $value)));
        $total = 0;

        foreach ($parts as $i => $part) {
            $total += $part * (60 ** $i);
        }

        return $total;
    }
}
