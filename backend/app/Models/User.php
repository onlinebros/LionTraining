<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'active_start_date',
        'referral_code',
        'role_id',
        'profile_photo',
        'phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                do {
                    $code = strtoupper(Str::random(8));
                } while (static::where('referral_code', $code)->exists());
                $user->referral_code = $code;
            }
            // Default new registrations to free_member
            if (empty($user->role_id)) {
                $free = Role::where('name', Role::FREE_MEMBER)->first();
                if ($free) {
                    $user->role_id = $free->id;
                }
            }
        });
    }

    // ── Role relationship ─────────────────────────────────────────────────────

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // ── Role helpers ──────────────────────────────────────────────────────────

    public function hasRole(string $name): bool
    {
        return $this->role?->name === $name;
    }

    public function isFreeMember(): bool   { return $this->hasRole(Role::FREE_MEMBER); }
    public function isPaidMember(): bool   { return $this->hasRole(Role::PAID_MEMBER); }
    public function isSupportAdmin(): bool { return $this->hasRole(Role::SUPPORT_ADMIN); }
    public function isSuperAdmin(): bool   { return $this->hasRole(Role::SUPER_ADMIN); }

    public function isAdmin(): bool
    {
        return $this->role?->is_admin === true;
    }

    // Admins automatically have paid-level access
    public function isPaidOrAbove(): bool
    {
        return $this->isPaidMember() || $this->isAdmin();
    }

    public function isMember(): bool
    {
        return !$this->isAdmin();
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'is_active'          => 'boolean',
            'active_start_date'  => 'date',
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
