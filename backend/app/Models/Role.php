<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    // Role name constants
    const FREE_MEMBER    = 'free_member';
    const PAID_MEMBER    = 'paid_member';
    const SUPPORT_ADMIN  = 'support_admin';
    const SUPER_ADMIN    = 'super_admin';

    protected $fillable = ['name', 'display_name', 'description', 'is_admin', 'level'];

    protected $casts = ['is_admin' => 'boolean'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }
}
