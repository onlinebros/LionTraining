<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmContact extends Model
{
    use SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'owner_id', 'assigned_to', 'created_by', 'updated_by',
        'linked_user_id', 'referred_by_user_id',
        'contact_type', 'status', 'lead_source',
        'first_name', 'last_name', 'email', 'phone', 'company', 'website',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'quick_note', 'custom_data',
        'last_contacted_at', 'next_followup_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_data'       => 'array',
            'last_contacted_at' => 'datetime',
            'next_followup_at'  => 'datetime',
        ];
    }

    // ── Status / type helpers ─────────────────────────────────────────────────

    public static array $statuses = [
        'new'                 => ['label' => 'New',                'color' => 'secondary'],
        'contacted'           => ['label' => 'Contacted',          'color' => 'info'],
        'interested'          => ['label' => 'Interested',         'color' => 'primary'],
        'presentation_sent'   => ['label' => 'Presentation Sent',  'color' => 'primary'],
        'follow_up_needed'    => ['label' => 'Follow-Up Needed',   'color' => 'warning'],
        'application_started' => ['label' => 'Application Started','color' => 'warning'],
        'purchased'           => ['label' => 'Purchased',          'color' => 'success'],
        'subscribed'          => ['label' => 'Subscribed',         'color' => 'success'],
        'became_affiliate'    => ['label' => 'Became Affiliate',   'color' => 'success'],
        'not_interested'      => ['label' => 'Not Interested',     'color' => 'danger'],
        'lost'                => ['label' => 'Lost',               'color' => 'danger'],
        're_engage_later'     => ['label' => 'Re-Engage Later',    'color' => 'dark'],
    ];

    public static array $contactTypes = [
        'prospect'    => 'Prospect',
        'lead'        => 'Lead',
        'customer'    => 'Customer',
        'affiliate'   => 'Affiliate',
        'team_member' => 'Team Member',
        'vendor'      => 'Vendor',
        'partner'     => 'Partner',
    ];

    public static array $leadSources = [
        'referral'      => 'Referral',
        'website'       => 'Website',
        'social'        => 'Social Media',
        'cold_outreach' => 'Cold Outreach',
        'event'         => 'Event',
        'ad'            => 'Advertisement',
        'other'         => 'Other',
    ];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getStatusLabelAttribute(): string
    {
        return static::$statuses[$this->status]['label'] ?? ucfirst($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return static::$statuses[$this->status]['color'] ?? 'secondary';
    }

    public function isOverdue(): bool
    {
        return $this->next_followup_at && $this->next_followup_at->isPast();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function linkedUser()
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function tags()
    {
        return $this->belongsToMany(CrmTag::class, 'crm_contact_tags', 'contact_id', 'tag_id');
    }

    public function notes()
    {
        return $this->hasMany(CrmNote::class, 'contact_id')->latest();
    }

    public function followups()
    {
        return $this->hasMany(CrmFollowup::class, 'contact_id')->latest('due_at');
    }

    public function pendingFollowups()
    {
        return $this->hasMany(CrmFollowup::class, 'contact_id')
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_at');
    }

    public function activities()
    {
        return $this->hasMany(CrmContactActivity::class, 'contact_id')->latest();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('owner_id', $userId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', now())
            ->whereNotIn('status', ['purchased', 'subscribed', 'became_affiliate', 'not_interested', 'lost']);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('next_followup_at', today());
    }
}
