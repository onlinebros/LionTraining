<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property string|null $stripe_connect_account_id
 * @property bool $connect_charges_enabled
 * @property bool $connect_payouts_enabled
 * @property bool $connect_details_submitted
 * @property string|null $connect_tax_reporting_status
 * @property array<string, mixed>|null $connect_requirements
 * @property \Illuminate\Support\Carbon|null $connect_synced_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // ── Placement state ───────────────────────────────────────────────────────
    // 'excluded' is for accounts that must never enter the structure at all:
    // staff, test accounts, the company node.
    public const PLACEMENT_QUEUED   = 'queued';
    public const PLACEMENT_PLACED   = 'placed';
    public const PLACEMENT_EXCLUDED = 'excluded';

    // ── Account state ─────────────────────────────────────────────────────────
    //
    // 'holding' is an imported position nobody has claimed yet: a real row,
    // really placed, with no owner, no password and no login. It is not a
    // person, and nothing that counts or displays partners may include one
    // without saying so. Use scopeActivated() — see the note on it.
    public const ACCOUNT_ACTIVE  = 'active';
    public const ACCOUNT_HOLDING = 'holding';

    // 'merged' is a position that was absorbed into another account — the
    // founder case, where somebody's imported position and their existing one
    // become a single account with a single team. The row is kept, out of the
    // tree and unable to log in, because the partner will quote its identifier
    // at us for years. See SpotMergeService.
    public const ACCOUNT_MERGED  = 'merged';

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

    /**
     * Rows that represent a person.
     *
     * The default for anything partner-facing or admin-facing that lists,
     * counts or pages over users. Unclaimed holding spots are structure, not
     * membership: showing them inflates a partner's team, inflates the admin
     * user list, and puts rows with no email in front of staff who will try to
     * contact them.
     *
     * Holding spots are reached deliberately, through the spots screens and
     * through the claim flow, never by forgetting this scope.
     */
    public function scopeActivated(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where($query->qualifyColumn('account_status'), self::ACCOUNT_ACTIVE);
    }

    /** Imported positions nobody has claimed yet. */
    public function scopeHolding(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where($query->qualifyColumn('account_status'), self::ACCOUNT_HOLDING);
    }

    /** Positions that were absorbed into another account. */
    public function scopeMerged(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where($query->qualifyColumn('account_status'), self::ACCOUNT_MERGED);
    }

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

    public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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
        // A credential in its own right: it is what lets somebody take
        // ownership of a position.
        'activation_code_hash',
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

            'claimed_at'          => 'datetime',
            'merged_at'           => 'datetime',
            'imported_at'         => 'datetime',
            'claim_locked_until'  => 'datetime',

            'connect_charges_enabled'   => 'boolean',
            'connect_payouts_enabled'   => 'boolean',
            'connect_details_submitted' => 'boolean',
            'connect_requirements'      => 'array',
            'connect_synced_at'         => 'datetime',
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

    // ── Holding spots ─────────────────────────────────────────────────────────

    public function partnerCompany(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PartnerCompany::class, 'partner_company_id');
    }

    public function partnerImport(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PartnerImport::class, 'partner_import_id');
    }

    /** An imported position with no owner yet. */
    public function isHolding(): bool
    {
        return $this->account_status === self::ACCOUNT_HOLDING;
    }

    /** Absorbed into another account, and no longer in the structure. */
    public function isMerged(): bool
    {
        return $this->account_status === self::ACCOUNT_MERGED;
    }

    /** The account this one was folded into, if it was. */
    public function mergedInto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_user_id');
    }

    /** A real member account — the state every normally-registered user is in. */
    public function isActivated(): bool
    {
        return $this->account_status !== self::ACCOUNT_HOLDING;
    }

    /** Was this position brought in from a partner company's list? */
    public function isImported(): bool
    {
        return $this->partner_company_id !== null;
    }

    /**
     * Does this partner have unclaimed positions directly beneath them?
     *
     * Directly, because that is all the spots screen shows — see
     * GenealogyService::directSpotCounts(). It also makes this an indexed
     * lookup on placement_parent_id rather than an ltree range scan, which
     * matters because it drives a sidebar link and so runs on every member page
     * render.
     *
     * Still cached: a partner seeing the link appear a little late after a
     * claim costs nothing, and an existence query on every request for every
     * member is a real bill.
     */
    public function hasHoldingSpotsBelow(): bool
    {
        return \Cache::remember(
            "user_{$this->id}_has_holding_spots",
            now()->addMinutes(5),
            fn () => static::query()->holding()->where('placement_parent_id', $this->id)->exists(),
        );
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

    /**
     * Enrolled under "wait for my commissions" and not yet billed.
     *
     * These partners can build and earn, but the training program stays locked
     * until their billing starts.
     */
    public function isOnCommissionHold(): bool
    {
        if ($this->isAdmin() || $this->billing_exempt === true) {
            return false;
        }

        return $this->activeSubscription()?->isCommissionHold() === true;
    }

    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    // ── Connect payout account ────────────────────────────────────────────────
    //
    // stripe_connect_account_id is written only by StripeConnectService and never
    // mass-assigned, for the same reason as provider_customer_id.

    public function connectIdentityClaims()
    {
        return $this->hasMany(ConnectIdentityClaim::class);
    }

    public function hasConnectAccount(): bool
    {
        return filled($this->stripe_connect_account_id);
    }

    /** Ready to receive a commission transfer. */
    public function canReceivePayouts(): bool
    {
        return $this->hasConnectAccount() && $this->connect_payouts_enabled === true;
    }

    /**
     * Stripe requirement codes blocking payouts now.
     *
     * @return array<int, string>
     */
    public function connectOutstandingRequirements(): array
    {
        $requirements = $this->connect_requirements ?? [];

        return array_values(array_unique(array_merge(
            $requirements['currently_due'] ?? [],
            $requirements['past_due'] ?? [],
        )));
    }

    /**
     * Codes Stripe will ask for later that are not already outstanding.
     *
     * @return array<int, string>
     */
    public function connectUpcomingRequirements(): array
    {
        $requirements = $this->connect_requirements ?? [];
        $future = $requirements['future'] ?? [];

        $upcoming = array_unique(array_merge(
            $requirements['eventually_due'] ?? [],
            $future['currently_due'] ?? [],
            $future['eventually_due'] ?? [],
        ));

        return array_values(array_diff($upcoming, $this->connectOutstandingRequirements()));
    }

    /** @return array<int, array{code: ?string, reason: ?string, requirement: ?string}> */
    public function connectRequirementErrors(): array
    {
        $requirements = $this->connect_requirements ?? [];

        return array_merge($requirements['errors'] ?? [], $requirements['future']['errors'] ?? []);
    }

    /** The earliest deadline Stripe has set, now or for future requirements. */
    public function connectRequirementDeadline(): ?\Illuminate\Support\Carbon
    {
        $requirements = $this->connect_requirements ?? [];

        $deadlines = array_filter([
            $requirements['current_deadline'] ?? null,
            $requirements['future']['current_deadline'] ?? null,
        ]);

        return $deadlines ? \Illuminate\Support\Carbon::createFromTimestamp(min($deadlines)) : null;
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
