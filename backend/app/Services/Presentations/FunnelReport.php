<?php

namespace App\Services\Presentations;

use App\Models\FunnelChoiceEvent;
use App\Models\FunnelParticipant;
use App\Models\PresentationFunnel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What people did with a flow.
 *
 * Two questions, and they want different shapes of answer. "Where is this
 * person and what did they pick?" is a list of people; "which branch is worth
 * keeping?" is a tally of choices. Both are scoped by who is asking, because a
 * member must never see another member's prospects — including in aggregate,
 * where it is easiest to forget.
 */
class FunnelReport
{
    /** @return Collection<int, array<string, mixed>> */
    public function people(PresentationFunnel $funnel, ?User $viewer): Collection
    {
        return $funnel->participants()
            ->visibleTo($viewer)
            ->with(['host:id,name', 'currentPresentation:id,title', 'choices'])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FunnelParticipant $p) => $this->personRow($p));
    }

    /** @return array<string, mixed> */
    private function personRow(FunnelParticipant $p): array
    {
        return [
            'id'        => $p->id,
            'name'      => $p->name,
            'email'     => $p->email,
            'host'      => $p->host?->name,
            'step'      => $p->currentPresentation?->title,
            'step_no'   => $p->stepNumber(),
            'seen'      => $p->pathLength(),
            'choices'   => $p->choices->count(),
            'path'      => $p->choices->pluck('label')->all(),
            'outcome'   => $p->outcomeLabel(),
            'watching'  => $p->isWatching(),
            'started'   => $p->started_at,
            'last_seen' => $p->last_seen_at,
            // The console is where a member actually acts on any of this,
            // so the row carries the way to get there.
            'thread'    => $p->thread_attendee_id,
        ];
    }

    /**
     * Which options people actually take.
     *
     * The reason to build a flow at all: a branch nobody picks is a video not
     * worth making, and one everybody picks is the thing to say sooner.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function choices(PresentationFunnel $funnel, ?User $viewer): Collection
    {
        $rows = FunnelChoiceEvent::query()
            ->where('funnel_id', $funnel->id)
            ->visibleTo($viewer)
            ->with('presentation:id,title')
            ->get();

        $total = $rows->count();

        return $rows
            // Grouped by the wording people saw, not by cue id: a cue that was
            // deleted and recreated is the same question to a prospect.
            ->groupBy(fn (FunnelChoiceEvent $e) => ($e->presentation?->title ?? '—').'|'.$e->label)
            ->map(fn (Collection $group) => $this->choiceRow($group, $total))
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @param  Collection<int, FunnelChoiceEvent>  $group
     * @return array<string, mixed>
     */
    private function choiceRow(Collection $group, int $total): array
    {
        return [
            'on'      => $group->first()->presentation?->title,
            'label'   => $group->first()->label,
            'kind'    => $group->first()->kind,
            'count'   => $group->count(),
            'share'   => $total > 0 ? round(($group->count() / $total) * 100) : 0,
            // Where in the video people tend to decide, which says whether
            // the ask is landing early or being sat through.
            'typical' => $this->median($group->pluck('at_seconds')->filter()->values()->all()),
        ];
    }

    public function totals(PresentationFunnel $funnel, ?User $viewer): array
    {
        $people = $this->people($funnel, $viewer);

        return [
            'started'  => $people->count(),
            'moved_on' => $people->where('choices', '>', 0)->count(),
            'asked'    => $people->whereNotNull('outcome')->count(),
            'watching' => $people->where('watching', true)->count(),
        ];
    }

    /** @param  array<int, int>  $values */
    private function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        $seconds = count($values) % 2 === 1
            ? $values[$middle]
            : (int) (($values[$middle - 1] + $values[$middle]) / 2);

        return \App\Models\PresentationAttendee::clock($seconds);
    }
}
