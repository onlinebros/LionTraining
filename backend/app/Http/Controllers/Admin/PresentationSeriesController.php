<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationSeries;
use App\Models\ScreenRecording;
use App\Services\Presentations\SeriesGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Repeating schedules: one recording, shown on set days at set times.
 */
class PresentationSeriesController extends Controller
{
    public function __construct(private readonly SeriesGenerator $generator)
    {
    }

    public function index()
    {
        return view('admin.presentations.series.index', [
            'series' => PresentationSeries::with('recording')
                ->withCount(['presentations as upcoming_count' => fn ($q) => $q->where('scheduled_at', '>=', now())])
                ->orderByDesc('is_active')
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function create()
    {
        return view('admin.presentations.series.form', [
            'series'     => new PresentationSeries([
                'weeks_ahead'       => 4,
                'is_active'         => true,
                'replay_visibility' => Presentation::REPLAY_NONE,
                'days'              => [],
                'times'             => [],
            ]),
            'recordings' => $this->selectableRecordings(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $series = PresentationSeries::create(
            $this->validated($request, creating: true) + ['created_by' => $request->user()->id]
        );

        $made = $this->generator->generate($series);

        return redirect()->route('admin.presentations.series.edit', $series)
            ->with('success', "Schedule saved. {$made} showing(s) added to the calendar.");
    }

    public function edit(PresentationSeries $series)
    {
        return view('admin.presentations.series.form', [
            'series'     => $series->load('recording'),
            'recordings' => $this->selectableRecordings(),
            'upcoming'   => $series->upcoming()->limit(30)->get(),
        ]);
    }

    public function update(Request $request, PresentationSeries $series): RedirectResponse
    {
        $series->update($this->validated($request));

        $made = $this->generator->generate($series);

        return back()->with('success', "Schedule updated. {$made} new showing(s) added.");
    }

    /**
     * Stopping a series leaves the showings it already made alone.
     *
     * People may already hold links to them, and their guest lists are real.
     * Cancelling individual future showings is done from the presentations
     * list, one at a time and on purpose.
     */
    public function destroy(PresentationSeries $series): RedirectResponse
    {
        $series->update(['is_active' => false]);
        $series->delete();

        return redirect()->route('admin.presentations.series.index')
            ->with('success', 'Schedule stopped. Showings already on the calendar are unaffected.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function validated(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'title'             => 'required|string|max:160',
            'description'       => 'nullable|string|max:2000',
            'recording_id'      => 'required|exists:screen_recordings,id',
            'days'              => 'required|array|min:1',
            'days.*'            => 'integer|between:0,6',
            'times'             => 'required|array|min:1',
            'times.*'           => 'required|date_format:H:i',
            'weeks_ahead'       => 'required|integer|min:1|max:26',
            'starts_on'         => 'nullable|date',
            'ends_on'           => 'nullable|date|after_or_equal:starts_on',
            'replay_visibility' => ['required', Rule::in(array_keys(Presentation::REPLAY_OPTIONS))],
        ], [
            'days.required'  => 'Pick at least one day of the week.',
            'times.required' => 'Add at least one time.',
        ]);

        // Deduplicate and order, so "7pm, 7pm, 9am" becomes "9am, 7pm" and the
        // generator cannot produce two identical showings.
        $data['days']  = collect($data['days'])->unique()->sort()->values()->all();
        $data['times'] = collect($data['times'])->unique()->sort()->values()->all();

        $data['collect_phone'] = $request->boolean('collect_phone');

        // An unticked checkbox is simply absent from the request, so on create
        // a missing flag means "nobody said otherwise" and a new schedule
        // should run. On update the form posts a hidden 0 alongside it, so
        // absence there really does mean the admin turned it off.
        $data['is_active'] = $creating
            ? $request->boolean('is_active', true)
            : $request->boolean('is_active');

        return $data;
    }

    private function selectableRecordings()
    {
        return ScreenRecording::ready()
            ->whereNotNull('duration_seconds')
            ->orderBy('title')
            ->get();
    }
}
