<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmTag extends Model
{
    protected $table = 'crm_tags';

    protected $fillable = ['user_id', 'name', 'color'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contacts()
    {
        return $this->belongsToMany(CrmContact::class, 'crm_contact_tags', 'tag_id', 'contact_id');
    }

    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        });
    }
}
