<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmFollowup extends Model
{
    protected $table = 'crm_followups';

    protected $fillable = [
        'contact_id', 'assigned_to', 'created_by',
        'title', 'description', 'type', 'priority', 'status',
        'due_at', 'completed_at', 'snoozed_until', 'completion_note',
    ];

    protected function casts(): array
    {
        return [
            'due_at'        => 'datetime',
            'completed_at'  => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    public static array $types = [
        'call'    => ['label' => 'Call',    'icon' => 'phone',          'color' => 'success'],
        'email'   => ['label' => 'Email',   'icon' => 'mail',           'color' => 'primary'],
        'sms'     => ['label' => 'SMS',     'icon' => 'message-square', 'color' => 'info'],
        'meeting' => ['label' => 'Meeting', 'icon' => 'users',          'color' => 'warning'],
        'zoom'    => ['label' => 'Zoom',    'icon' => 'video',          'color' => 'primary'],
        'social'  => ['label' => 'Social',  'icon' => 'share-2',        'color' => 'info'],
        'other'   => ['label' => 'Other',   'icon' => 'more-horizontal','color' => 'dark'],
    ];

    public static array $priorities = [
        'low'    => ['label' => 'Low',    'color' => 'secondary'],
        'medium' => ['label' => 'Medium', 'color' => 'primary'],
        'high'   => ['label' => 'High',   'color' => 'warning'],
        'urgent' => ['label' => 'Urgent', 'color' => 'danger'],
    ];

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_at->isPast();
    }

    public function getTypeLabelAttribute(): string
    {
        return static::$types[$this->type]['label'] ?? ucfirst($this->type);
    }

    public function getPriorityColorAttribute(): string
    {
        return static::$priorities[$this->priority]['color'] ?? 'secondary';
    }

    public function contact()
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'overdue']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')->where('due_at', '<', now());
    }

    public function scopeDueToday($query)
    {
        return $query->where('status', 'pending')->whereDate('due_at', today());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }
}
