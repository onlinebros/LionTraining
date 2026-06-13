<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'sponsor_id');
    }

    public function sponsoredUsers()
    {
        return $this->hasMany(Sponsorship::class, 'sponsored_id');
    }

    public function sponsors()
    {
        return $this->belongsToMany(User::class, 'sponsorships', 'sponsored_id', 'sponsor_id')
            ->withPivot('status', 'notes', 'accepted_at')
            ->withTimestamps();
    }

    public function sponsees()
    {
        return $this->belongsToMany(User::class, 'sponsorships', 'sponsor_id', 'sponsored_id')
            ->withPivot('status', 'notes', 'accepted_at')
            ->withTimestamps();
    }
}
