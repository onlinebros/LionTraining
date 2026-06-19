<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmContactActivity extends Model
{
    protected $table = 'crm_contact_activities';

    protected $fillable = [
        'contact_id', 'user_id', 'type', 'description',
        'old_value', 'new_value', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    // Activity type → icon mapping for the timeline
    public static array $icons = [
        'contact_created'    => ['icon' => 'user-plus',   'color' => 'success'],
        'status_changed'     => ['icon' => 'refresh-cw',  'color' => 'primary'],
        'note_added'         => ['icon' => 'file-text',   'color' => 'info'],
        'call_logged'        => ['icon' => 'phone',        'color' => 'success'],
        'email_logged'       => ['icon' => 'mail',         'color' => 'primary'],
        'sms_logged'         => ['icon' => 'message-square','color' => 'info'],
        'meeting_logged'     => ['icon' => 'users',        'color' => 'warning'],
        'followup_created'   => ['icon' => 'clock',        'color' => 'warning'],
        'followup_completed' => ['icon' => 'check-circle', 'color' => 'success'],
        'followup_overdue'   => ['icon' => 'alert-circle', 'color' => 'danger'],
        'tag_added'          => ['icon' => 'tag',          'color' => 'secondary'],
        'tag_removed'        => ['icon' => 'tag',          'color' => 'secondary'],
        'field_updated'      => ['icon' => 'edit-2',       'color' => 'secondary'],
        'assigned'           => ['icon' => 'user-check',   'color' => 'primary'],
    ];

    public function getIconAttribute(): string
    {
        return static::$icons[$this->type]['icon'] ?? 'activity';
    }

    public function getIconColorAttribute(): string
    {
        return static::$icons[$this->type]['color'] ?? 'secondary';
    }

    public function contact()
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
