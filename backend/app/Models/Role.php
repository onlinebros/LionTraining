<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    // Role name constants
    const FREE_MEMBER    = 'free_member';
    const PAID_MEMBER    = 'paid_member';
    // A vendor whose products our partners sell. Not a member and not an
    // admin — it has its own section, and is_admin stays false so every
    // existing admin check keeps them out without being told to.
    const PRODUCT_PARTNER = 'product_partner';
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
