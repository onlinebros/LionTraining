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
