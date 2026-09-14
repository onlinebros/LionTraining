<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // ── Placement state ───────────────────────────────────────────────────────
    // 'excluded' is for accounts that must never enter the structure at all:
    // staff, test accounts, the company node.
    public const PLACEMENT_QUEUED   = 'queued';
    public const PLACEMENT_PLACED   = 'placed';
    public const PLACEMENT_EXCLUDED = 'excluded';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'active_start_date',
        'referral_code',
        'role_id',
        'sponsor_id',
        'prelaunch_preview',
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
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'is_active'           => 'boolean',
            'active_start_date'   => 'date',
            'prelaunch_preview'   => 'boolean',
            'placement_queued_at' => 'datetime',
            'placed_at'           => 'datetime',
            'billing_exempt'      => 'boolean',
        ];
    }

    // ── Genealogy ─────────────────────────────────────────────────────────────

    /** Who enrolled this partner. Permanent once set. */
    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    /** Partners this one personally enrolled. */
    public function recruits()
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    /** Who this partner sits beneath in the paying structure. */
    public function placementParent()
    {
        return $this->belongsTo(User::class, 'placement_parent_id');
    }

    /** Partners placed directly beneath this one. */
    public function placementChildren()
    {
        return $this->hasMany(User::class, 'placement_parent_id');
    }

    public function isPlaced(): bool
    {
        return $this->placement_status === self::PLACEMENT_PLACED;
    }

    // ── Billing ───────────────────────────────────────────────────────────────
    //
    // provider_customer_id is deliberately absent from $fillable: it is written
    // only by the billing service, and a mass-assigned customer id would let a
    // crafted request point one user's account at another's stored cards.

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /** The subscription currently granting access, if any. */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->entitling()
            ->orderByDesc('current_period_end')
            ->first();
    }

    /**
     * May this user reach subscription-gated areas?
     *
     * Admins and explicitly exempted accounts (founders, staff, comped
     * partners) are checked before the subscription lookup — their access does
     * not come from a card, and demoting them when one lapses would lock staff
     * out of their own product.
     */
    public function hasActiveMembership(): bool
    {
        if ($this->isAdmin() || $this->billing_exempt === true) {
            return true;
        }

        return $this->activeSubscription() !== null;
    }

    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    // ── Pre-launch ────────────────────────────────────────────────────────────

    /**
     * May this user reach sections the pre-launch guard has closed?
     *
     * Admins pass automatically — they are the people who need to check that a
     * closed section still works before it opens. Everyone else needs the flag
     * set explicitly, via `php artisan prelaunch:preview`.
     */
    public function canPreviewPrelaunch(): bool
    {
        return $this->isAdmin() || $this->prelaunch_preview === true;
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
