<?php

namespace App\Services\Presentations;

use App\Models\CrmContact;
use App\Models\PresentationAttendee;
use App\Models\User;

/**
 * Pushes a presentation prospect into the CRM.
 *
 * Deliberately a thin, one-directional bridge rather than a sync. The CRM is
 * where a relationship is worked over months; a presentation is one evening in
 * it. So this creates or updates the contact and hands over what the
 * presentation knows — who invited them, what they watched, whether they
 * converted — and stops there. Nothing reads back.
 *
 * That also means the day the CRM grows properly, this is the only file that
 * has to learn about it.
 */
class ProspectToCrm
{
    /*
     * The CRM's lead_source is a constrained enum owned by the CRM, and 'event'
     * is exactly what a presentation is. Widening someone else's enum for a
     * finer label would be the wrong trade — the detail lives in custom_data,
     * where it can grow without a migration.
     */
    public const SOURCE = 'event';

    /**
     * Create or update the CRM contact for this guest.
     *
     * Matched on owner plus email, because a contact belongs to the member who
     * owns the relationship — the same address under two members is two
     * relationships, exactly as it is in the prospect report.
     */
    public function push(PresentationAttendee $attendee, User $actor): CrmContact
    {
        $owner = $attendee->host ?? $actor;
        [$first, $last] = $this->splitName($attendee->name);

        $contact = CrmContact::firstOrNew([
            'owner_id' => $owner->id,
            'email'    => $attendee->email,
        ]);

        $contact->fill([
            'first_name'  => $first,
            'last_name'   => $last ?: $contact->last_name,
            'phone'       => $attendee->phone ?: $contact->phone,
            'created_by'  => $contact->exists ? $contact->created_by : $actor->id,
            'updated_by'  => $actor->id,
            'lead_source' => $contact->lead_source ?: self::SOURCE,
            'referred_by_user_id' => $contact->referred_by_user_id ?: $attendee->host_user_id,
        ]);

        // Only claim the account link when we actually have one; overwriting a
        // manually-linked contact with null would lose somebody's work.
        if ($attendee->converted_user_id) {
            $contact->linked_user_id = $attendee->converted_user_id;
        }

        $contact->custom_data = array_merge($contact->custom_data ?? [], [
            'presentation' => $this->snapshot($attendee),
        ]);

        $contact->save();

        $attendee->forceFill(['crm_contact_id' => $contact->id])->save();

        return $contact;
    }

    /**
     * What the presentation knows, in a shape a CRM can read without knowing
     * anything about presentations.
     */
    private function snapshot(PresentationAttendee $attendee): array
    {
        $siblings = PresentationAttendee::where('email', $attendee->email)
            ->where('host_user_id', $attendee->host_user_id)
            ->with('presentation:id,title,scheduled_at')
            ->get();

        return [
            'registrations' => $siblings->count(),
            'attended'      => $siblings->filter->hasJoined()->count(),
            'watch_seconds' => (int) $siblings->sum('watch_seconds'),
            'clicked_cta'   => $siblings->contains(fn ($a) => $a->cta_clicked_at !== null),
            'converted'     => $attendee->hasConverted(),
            'converted_at'  => $attendee->converted_at?->toIso8601String(),
            'match'         => $attendee->conversion_match,
            'watched'       => $siblings->map(fn ($a) => [
                'title'    => $a->presentation?->title,
                'when'     => $a->presentation?->scheduled_at?->toIso8601String(),
                'attended' => $a->hasJoined(),
                'seconds'  => (int) $a->watch_seconds,
            ])->values()->all(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
