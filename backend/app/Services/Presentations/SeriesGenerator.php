<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a repeating schedule into real showings.
 *
 * Runs ahead of time rather than on demand so every occurrence is a normal
 * presentation with its own link, its own guest list and its own conversation
 * from the moment it exists — a member can share next Tuesday's link today.
 */
class SeriesGenerator
{
    /**
     * Materialise every occurrence of $series inside its window.
     *
     * @return int how many were created
     */
    public function generate(PresentationSeries $series): int
    {
        if (! $series->is_active) {
            return 0;
        }

        $recording = $series->recording;

        if (! $recording?->isReady() || ! $recording->duration_seconds) {
            return 0;
        }

        $zone  = Presentation::bookingTimezone();
        $days  = collect($series->days ?? [])->map(fn ($d) => (int) $d)->all();
        $times = collect($series->times ?? [])->filter()->values();

        if ($days === [] || $times->isEmpty()) {
            return 0;
        }

        $cursor = Carbon::now($zone)->startOfDay();

        if ($series->starts_on && $series->starts_on->gt($cursor)) {
            $cursor = $series->starts_on->copy()->startOfDay()->shiftTimezone($zone);
        }

        $until = Carbon::now($zone)->startOfDay()->addWeeks(max(1, $series->weeks_ahead));

        if ($series->ends_on && $series->ends_on->lt($until)) {
            $until = $series->ends_on->copy()->endOfDay()->shiftTimezone($zone);
        }

        // Everything this series has ever produced, so a cancelled occurrence
        // is not quietly resurrected on the next run. Soft-deleted rows count.
        $existing = Presentation::withTrashed()
            ->where('series_id', $series->id)
            ->pluck('scheduled_at')
            ->map(fn ($at) => $at->utc()->format('Y-m-d H:i'))
            ->flip()
            ->map(fn () => true);

        $created = 0;

        for ($day = $cursor->copy(); $day->lte($until); $day->addDay()) {
            if (! in_array($day->dayOfWeek, $days, true)) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_pad(explode(':', (string) $time), 2, '0');

                $localStart = $day->copy()->setTime((int) $hour, (int) $minute);
                $startsAt   = $localStart->copy()->utc();

                // Never backfill. A showing generated into the past would be
                // started immediately by the runner and end before anyone knew.
                if ($startsAt->lte(now())) {
                    continue;
                }

                if ($existing->has($startsAt->format('Y-m-d H:i'))) {
                    continue;
                }

                Presentation::create([
                    'series_id'         => $series->id,
                    'recording_id'      => $recording->id,
                    'title'             => $series->title,
                    'description'       => $series->description,
                    'slug'              => Presentation::uniqueSlug(
                        $series->title.' '.$localStart->format('M j gia')
                    ),
                    'scheduled_at'      => $startsAt,
                    'duration_seconds'  => $recording->duration_seconds,
                    'replay_visibility' => $series->replay_visibility,
                    'collect_phone'     => $series->collect_phone,
                    // Every occurrence asks for the same thing the series does.
                    'cta_type'          => $series->cta_type ?: config('presentations.default_cta'),
                    'cta_headline'      => $series->cta_headline,
                    'cta_label'         => $series->cta_label,
                    'cta_note'          => $series->cta_note,
                    'cta_url'           => $series->cta_url,
                    'created_by'        => $series->created_by,
                ]);

                $existing->put($startsAt->format('Y-m-d H:i'), true);
                $created++;
            }
        }

        return $created;
    }

    /** @return int total occurrences created across every active series */
    public function generateAll(): int
    {
        return PresentationSeries::where('is_active', true)
            ->with('recording')
            ->get()
            ->sum(fn (PresentationSeries $series) => $this->generate($series));
    }
}
