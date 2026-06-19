<?php

namespace App\Services;

use App\Models\CrmContact;
use App\Models\CrmContactActivity;
use App\Models\CrmFollowup;
use App\Models\CrmNote;
use Illuminate\Support\Facades\DB;

class CrmService
{
    // ── Activity logging ──────────────────────────────────────────────────────

    public function log(
        CrmContact $contact,
        string $type,
        string $description,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $metadata = null
    ): CrmContactActivity {
        return CrmContactActivity::create([
            'contact_id'  => $contact->id,
            'user_id'     => auth()->id(),
            'type'        => $type,
            'description' => $description,
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
            'metadata'    => $metadata,
        ]);
    }

    // ── Contact creation ──────────────────────────────────────────────────────

    public function createContact(array $data, int $ownerId): CrmContact
    {
        return DB::transaction(function () use ($data, $ownerId) {
            $data['owner_id']   = $ownerId;
            $data['created_by'] = auth()->id();
            $data['assigned_to'] = $data['assigned_to'] ?? $ownerId;

            $contact = CrmContact::create($data);

            $this->log($contact, 'contact_created', "Contact {$contact->full_name} was created");

            if (! empty($data['tags'])) {
                $contact->tags()->sync($data['tags']);
                $this->log($contact, 'tag_added', 'Tags assigned to contact');
            }

            return $contact;
        });
    }

    // ── Contact update ────────────────────────────────────────────────────────

    public function updateContact(CrmContact $contact, array $data): CrmContact
    {
        return DB::transaction(function () use ($contact, $data) {
            $data['updated_by'] = auth()->id();

            // Track status change
            if (isset($data['status']) && $data['status'] !== $contact->status) {
                $oldLabel = $contact->status_label;
                $contact->update($data);
                $newLabel = $contact->fresh()->status_label;
                $this->log(
                    $contact, 'status_changed',
                    "Status changed from {$oldLabel} to {$newLabel}",
                    $oldLabel, $newLabel
                );
            } else {
                $contact->update($data);
                $this->log($contact, 'field_updated', 'Contact details updated');
            }

            // Sync tags if provided
            if (isset($data['tags'])) {
                $contact->tags()->sync($data['tags']);
            }

            return $contact->fresh();
        });
    }

    // ── Notes ─────────────────────────────────────────────────────────────────

    public function addNote(CrmContact $contact, array $data): CrmNote
    {
        return DB::transaction(function () use ($contact, $data) {
            $note = CrmNote::create([
                'contact_id'  => $contact->id,
                'user_id'     => auth()->id(),
                'type'        => $data['type'] ?? 'note',
                'title'       => $data['title'] ?? null,
                'body'        => $data['body'],
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $typeLabel = CrmNote::$types[$note->type]['label'] ?? 'Note';
            $this->log(
                $contact,
                $note->type . '_logged',
                "{$typeLabel} logged: " . ($note->title ?? substr($note->body, 0, 60))
            );

            // Update last contacted if it's a communication-type note
            if (in_array($note->type, ['call', 'email', 'sms', 'meeting', 'zoom', 'social', 'voicemail'])) {
                $contact->update(['last_contacted_at' => now()]);
            }

            return $note;
        });
    }

    // ── Follow-ups ────────────────────────────────────────────────────────────

    public function createFollowup(CrmContact $contact, array $data): CrmFollowup
    {
        return DB::transaction(function () use ($contact, $data) {
            $followup = CrmFollowup::create([
                'contact_id'  => $contact->id,
                'assigned_to' => $data['assigned_to'] ?? auth()->id(),
                'created_by'  => auth()->id(),
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'type'        => $data['type'] ?? 'call',
                'priority'    => $data['priority'] ?? 'medium',
                'status'      => 'pending',
                'due_at'      => $data['due_at'],
            ]);

            $contact->update(['next_followup_at' => $followup->due_at]);

            $this->log(
                $contact, 'followup_created',
                "Follow-up scheduled: {$followup->title} due " . $followup->due_at->format('M d, Y g:i A')
            );

            return $followup;
        });
    }

    public function completeFollowup(CrmFollowup $followup, ?string $note = null): CrmFollowup
    {
        return DB::transaction(function () use ($followup, $note) {
            $followup->update([
                'status'          => 'completed',
                'completed_at'    => now(),
                'completion_note' => $note,
            ]);

            $contact = $followup->contact;

            $this->log(
                $contact, 'followup_completed',
                "Follow-up completed: {$followup->title}"
            );

            // Recalculate next pending follow-up date
            $next = $contact->followups()
                ->whereIn('status', ['pending'])
                ->where('due_at', '>=', now())
                ->orderBy('due_at')
                ->first();

            $contact->update(['next_followup_at' => $next?->due_at]);

            return $followup->fresh();
        });
    }

    public function snoozeFollowup(CrmFollowup $followup, string $until): CrmFollowup
    {
        $followup->update([
            'status'        => 'snoozed',
            'snoozed_until' => $until,
        ]);

        $this->log(
            $followup->contact, 'field_updated',
            "Follow-up snoozed until " . \Carbon\Carbon::parse($until)->format('M d, Y g:i A')
        );

        return $followup->fresh();
    }

    // ── Overdue follow-up marking (called by scheduled task or on-demand) ─────

    public function markOverdueFollowups(): int
    {
        return CrmFollowup::where('status', 'pending')
            ->where('due_at', '<', now())
            ->update(['status' => 'overdue']);
    }

    // ── Dashboard stats ───────────────────────────────────────────────────────

    public function dashboardStats(?int $ownerId = null): array
    {
        $base = CrmContact::query();
        if ($ownerId) {
            $base->where('owner_id', $ownerId);
        }

        $followupBase = CrmFollowup::query();
        if ($ownerId) {
            $followupBase->where('assigned_to', $ownerId);
        }

        return [
            'total_contacts'   => (clone $base)->count(),
            'new_leads'        => (clone $base)->whereIn('status', ['new', 'contacted', 'interested'])->count(),
            'hot_leads'        => (clone $base)->whereIn('status', ['interested', 'presentation_sent', 'follow_up_needed', 'application_started'])->count(),
            'customers'        => (clone $base)->whereIn('status', ['purchased', 'subscribed', 'became_affiliate'])->count(),
            'followups_today'  => (clone $followupBase)->dueToday()->count(),
            'overdue'          => (clone $followupBase)->overdue()->count(),
        ];
    }
}
