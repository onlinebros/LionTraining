<?php

namespace App\Services\Presentations;

use App\Models\PresentationAttendee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One row per person, not per registration.
 *
 * A prospect who came to three calls is one prospect who came three times —
 * that is what a member needs to see before deciding what to say to them. The
 * attendee rows stay the record of what happened; this is the view over them.
 *
 * Grouped by the address they watched under, which is stable even when the
 * address they eventually sign up with is not.
 */
class ProspectReport
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function for(?User $viewer, array $filters = []): Collection
    {
        $rows = PresentationAttendee::query()
            ->visibleTo($viewer)
            ->with(['presentation:id,title,scheduled_at', 'host:id,name', 'convertedUser:id,name,email,created_at'])
            ->when($filters['host_id'] ?? null, fn ($q, $id) => $q->where('host_user_id', $id))
            ->when($filters['presentation_id'] ?? null, fn ($q, $id) => $q->where('presentation_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
            ))
            ->orderByDesc('registered_at')
            ->get();

        return $rows
            // Same person, same inviting member. Deliberately not email alone:
            // two members may each have invited the same address, and those are
            // two prospects with two separate relationships.
            ->groupBy(fn (PresentationAttendee $a) => $a->host_user_id.'|'.mb_strtolower($a->email))
            ->map(fn (Collection $group) => $this->summarise($group))
            ->values()
            ->sortByDesc(fn (array $row) => [$row['converted'] ? 1 : 0, $row['watch_seconds']])
            ->values();
    }

    /** Headline numbers for whoever is looking. */
    public function totals(?User $viewer, array $filters = []): array
    {
        $prospects = $this->for($viewer, $filters);

        $attended  = $prospects->where('attended', true);
        $converted = $prospects->where('converted', true);

        return [
            'prospects'  => $prospects->count(),
            'attended'   => $attended->count(),
            'clicked'    => $prospects->where('clicked', true)->count(),
            'converted'  => $converted->count(),
            // Of those who actually turned up — a show-up rate and a close rate
            // are different questions, and blending them flatters nobody.
            'conversion' => $attended->count() > 0
                ? round(($converted->count() / $attended->count()) * 100, 1)
                : 0.0,
            'watch_hours' => round($prospects->sum('watch_seconds') / 3600, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function summarise(Collection $group): array
    {
        /** @var PresentationAttendee $latest */
        $latest    = $group->sortByDesc('registered_at')->first();
        $converted = $group->firstWhere('converted_user_id', '!=', null);
        $attended  = $group->filter->hasJoined();

        return [
            'attendee_id' => $latest->id,
            'name'        => $latest->name,
            'email'       => $latest->email,
            'phone'       => $group->pluck('phone')->filter()->first(),
            'host'        => $latest->host?->name,
            'host_id'     => $latest->host_user_id,

            'registrations' => $group->count(),
            'attended'      => $attended->isNotEmpty(),
            'attended_count'=> $attended->count(),
            'watch_seconds' => (int) $group->sum('watch_seconds'),
            'watch_label'   => $this->duration((int) $group->sum('watch_seconds')),
            'last_seen'     => $group->max('last_seen_at'),
            'first_seen'    => $group->min('registered_at'),

            'clicked'   => $group->contains(fn ($a) => $a->cta_clicked_at !== null),
            'converted' => $converted !== null,
            'converted_at'    => $converted?->converted_at,
            'converted_user'  => $converted?->convertedUser?->name,
            'converted_email' => $converted?->convertedUser?->email,
            'match'           => $converted?->conversion_match,
            // The case they asked about: watched under one address, signed up
            // under another. The member needs to know it is the same human.
            'email_changed'   => (bool) $converted?->signedUpUnderAnotherEmail(),

            'crm_contact_id' => $group->pluck('crm_contact_id')->filter()->first(),

            // What they have already seen, so a member can pick up where the
            // prospect actually is rather than starting from the top again.
            'watched' => $group->map(fn (PresentationAttendee $a) => [
                'title'     => $a->presentation?->title,
                'joined_at' => $a->formattedJoinOffset(),
                'watched'   => $a->formattedWatchTime(),
                'attended'  => $a->hasJoined(),
                'when'      => $a->presentation?->scheduled_at,
            ])->values()->all(),
        ];
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes < 60
            ? $minutes.'m'
            : intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }
}
