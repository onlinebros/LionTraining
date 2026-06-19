<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    protected $fillable = [
        'level', 'message', 'file', 'line', 'trace',
        'url', 'method', 'context', 'user_id',
        'status', 'resolution_notes', 'resolved_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', ['new', 'acknowledged']);
    }

    public function isNew(): bool       { return $this->status === 'new'; }
    public function isAcknowledged(): bool { return $this->status === 'acknowledged'; }
    public function isResolved(): bool  { return $this->status === 'resolved'; }
}
