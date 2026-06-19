<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmNote extends Model
{
    protected $table = 'crm_notes';

    protected $fillable = [
        'contact_id', 'user_id', 'type', 'title', 'body', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public static array $types = [
        'note'      => ['label' => 'Note',       'icon' => 'file-text',  'color' => 'secondary'],
        'call'      => ['label' => 'Call',        'icon' => 'phone',      'color' => 'success'],
        'email'     => ['label' => 'Email',       'icon' => 'mail',       'color' => 'primary'],
        'sms'       => ['label' => 'SMS',         'icon' => 'message-square', 'color' => 'info'],
        'meeting'   => ['label' => 'Meeting',     'icon' => 'users',      'color' => 'warning'],
        'zoom'      => ['label' => 'Zoom',        'icon' => 'video',      'color' => 'primary'],
        'social'    => ['label' => 'Social',      'icon' => 'share-2',    'color' => 'info'],
        'voicemail' => ['label' => 'Voicemail',   'icon' => 'mic',        'color' => 'warning'],
        'other'     => ['label' => 'Other',       'icon' => 'more-horizontal', 'color' => 'dark'],
    ];

    public function getTypeLabelAttribute(): string
    {
        return static::$types[$this->type]['label'] ?? ucfirst($this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return static::$types[$this->type]['icon'] ?? 'file-text';
    }

    public function getTypeColorAttribute(): string
    {
        return static::$types[$this->type]['color'] ?? 'secondary';
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
