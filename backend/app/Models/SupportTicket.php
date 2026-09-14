<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'ticket_number',
        'subject',
        'category',
        'priority',
        'status',
        'source_url',
        'assigned_to',
        'closed_at',
        'channel',
        'requester_name',
        'requester_email',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    private static function generateTicketNumber(): string
    {
        $last = static::max('id') ?? 0;
        return 'TKT-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id')->orderBy('created_at');
    }

    // ── Channel / requester ───────────────────────────────────────────────────
    //
    // Website tickets come from the public contact form: no user, and the
    // requester's typed name/email are stored on the ticket. Member tickets
    // fall back to the linked user.

    public function isFromWebsite(): bool
    {
        return $this->channel === self::CHANNEL_WEBSITE;
    }

    public function requesterDisplayName(): string
    {
        return $this->requester_name ?: ($this->user?->name ?? '—');
    }

    public function requesterDisplayEmail(): ?string
    {
        return $this->requester_email ?: $this->user?->email;
    }

    /** mailto: link to the requester, subject prefilled with the ticket number. */
    public function requesterMailtoUrl(): ?string
    {
        $email = $this->requesterDisplayEmail();

        if (blank($email)) {
            return null;
        }

        $to = implode('@', array_map('rawurlencode', explode('@', $email)));

        return 'mailto:'.$to.'?subject='.rawurlencode("Re: [{$this->ticket_number}] {$this->subject}");
    }

    /**
     * A member whose account email matches what a website requester typed.
     * UNVERIFIED — anyone can type any address — so it is a hint for staff,
     * never a reason to link the ticket to that account.
     */
    public function unverifiedMemberMatch(): ?User
    {
        if (! $this->isFromWebsite() || blank($this->requester_email)) {
            return null;
        }

        return User::whereRaw('lower(email) = ?', [mb_strtolower($this->requester_email)])->first();
    }

    public function isOpen(): bool    { return $this->status === 'open'; }
    public function isInProgress(): bool { return $this->status === 'in_progress'; }
    public function isClosed(): bool  { return $this->status === 'closed'; }

    public function statusBadgeClass(): string
    {
        return match($this->status) {
            'open'        => 'badge-light-primary',
            'in_progress' => 'badge-light-warning',
            'closed'      => 'badge-light-success',
            default       => 'badge-light-secondary',
        };
    }

    public function priorityBadgeClass(): string
    {
        return match($this->priority) {
            'high'   => 'badge-light-danger',
            'low'    => 'badge-light-info',
            default  => 'badge-light-secondary',
        };
    }

    public const CATEGORIES = [
        'general'   => 'General',
        'technical' => 'Technical',
        'billing'   => 'Billing',
        'training'  => 'Training',
        'other'     => 'Other',
        'account'          => 'Account access',
        'partner_program'  => 'Partner Program',
        'partner_products' => 'Partner products',
        'privacy'          => 'Privacy request',
    ];

    public const CHANNEL_MEMBER = 'member';

    public const CHANNEL_WEBSITE = 'website';

    /** Topics the public website contact form may send. Each is a CATEGORIES key. */
    public const WEBSITE_TOPICS = [
        'general',
        'billing',
        'account',
        'technical',
        'partner_program',
        'partner_products',
        'privacy',
        'other',
    ];

    public const PRIORITIES = [
        'low'    => 'Low',
        'normal' => 'Normal',
        'high'   => 'High',
    ];

    public const STATUSES = [
        'open'        => 'Open',
        'in_progress' => 'In Progress',
        'closed'      => 'Closed',
    ];
}
