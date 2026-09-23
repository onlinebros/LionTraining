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
        // Which section they land on after signing in; null = the default for
        // their kind of account. See landingRoute().
        'landing_preference',
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

    /**
     * Newest sign-ups first.
     *
     * A claimed spot counts from its claim, not its import: `created_at` on
     * an imported position is when the import ran, which says nothing about
     * when the person actually joined.
     */
    public function scopeLatestRegistered(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->orderByRaw('COALESCE('.$query->qualifyColumn('claimed_at').', '.$query->qualifyColumn('created_at').') DESC');
    }

    /** When this person signed up: the claim for an imported spot, otherwise account creation. */
    public function registeredAt(): ?\Illuminate\Support\Carbon
    {
        return $this->claimed_at ?? $this->created_at;
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
    public function isProductPartner(): bool { return $this->hasRole(Role::PRODUCT_PARTNER); }
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
            // "Tell me when the training program opens." Null = they have not asked.
            'training_interest_at' => 'datetime',

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

    // ── Product partner access ────────────────────────────────────────────────
    //
    // Which vendor's products this account may look at, when it is a product
    // partner. The role alone grants nothing — see App\Support\ProductPartner.

    public function productPartnerAssignments()
    {
        return $this->hasMany(ProductPartnerAssignment::class);
    }

    /**
     * May this account use the member back office?
     *
     * Everybody except a product partner already can. A product partner is an
     * outside company's employee, and reaches it only once somebody has put
     * them on a business line — which is how "this vendor's sales people also
     * sell for us, their finance people do not" is expressed.
     *
     * EXPLICIT rows only, never opportunities(): every account with no line
     * recorded reads as the training line, which requires a card. Accepting
     * that default here would walk a vendor straight into a card capture
     * screen for a membership nobody sold them — the exact failure the whole
     * arrangement exists to avoid.
     */
    public function canUseMemberArea(): bool
    {
        if (! $this->isProductPartner()) {
            return true;
        }

        return $this->relationLoaded('opportunityAssociations')
            ? $this->opportunityAssociations->isNotEmpty()
            : $this->opportunityAssociations()->exists();
    }

    /** Is the product partner portal open to this account? */
    public function canUsePartnerPortal(): bool
    {
        return \App\Support\ProductPartner::hasAnyAccess($this);
    }

    // ── Where they land after signing in ──────────────────────────────────────

    /**
     * The sections this account could sensibly be sent to, as key => label.
     *
     * Fewer than two means there is no choice to offer, and the control is not
     * rendered at all.
     *
     * @return array<string, string>
     */
    public function landingOptions(): array
    {
        $options = [];

        if ($this->isAdmin()) {
            $options['admin'] = 'Admin panel';
        }

        if ($this->canUseMemberArea()) {
            $options['member'] = 'Member area';
        }

        if ($this->canUsePartnerPortal()) {
            $options['portal'] = 'Partner portal';
        }

        return $options;
    }

    /**
     * The route to send them to, honouring their choice where it still applies.
     *
     * A stored preference for a section they have since lost — the business
     * line was removed, the products were unlinked — is ignored rather than
     * obeyed, so losing access can never strand somebody on a redirect to a
     * page that bounces them back.
     */
    public function landingRoute(): string
    {
        $options = $this->landingOptions();
        $choice  = $this->landing_preference;

        if ($choice === null || ! array_key_exists($choice, $options)) {
            // The default for this kind of account, in order of how strongly
            // the section defines them.
            $choice = match (true) {
                $this->isAdmin()             => 'admin',
                $this->canUsePartnerPortal() => 'portal',
                default                      => 'member',
            };
        }

        return match ($choice) {
            'admin'  => route('admin.dashboard'),
            'portal' => route('product-partner.dashboard'),
            default  => route('member.dashboard'),
        };
    }

    // ── Opportunities ─────────────────────────────────────────────────────────
    //
    // Which business lines this member joined us for. See
    // config/opportunities.php for what they are, and App\Support\Opportunity
    // for the registry.
    //
    // `opportunity` on this row is the PRIMARY one — the door they came in by —
    // and is what decides whether a card is wanted. `user_opportunities` holds
    // every line they hold, and access is the union of them, so adding the
    // training program to a PlasmaGuard partner is one row.

    public function opportunityAssociations()
    {
        return $this->hasMany(UserOpportunity::class);
    }

    /**
     * The line they came in by. Never null: an unset or retired key reads as
     * the default.
     *
     * The column is `primary_opportunity` rather than `opportunity` so this
     * method can be called `opportunity()` without colliding with an attribute.
     * Eloquent resolves `$model->opportunity` against the attribute bag first
     * and falls through to the method only when the column is absent — which is
     * exactly what happens under a `select()` that omits it, and it would then
     * throw for returning something that is not a relation.
     */
    public function opportunity(): \App\Support\Opportunity
    {
        return \App\Support\Opportunity::get($this->primary_opportunity);
    }

    public function opportunityKey(): string
    {
        return $this->opportunity()->key;
    }

    /**
     * Every line this member holds, primary first.
     *
     * A member with no rows at all holds their primary and nothing else, which
     * is what every account older than this feature means.
     *
     * @return \Illuminate\Support\Collection<int,\App\Support\Opportunity>
     */
    public function opportunities(): \Illuminate\Support\Collection
    {
        $primary = $this->opportunity();

        $others = $this->relationLoaded('opportunityAssociations')
            ? $this->opportunityAssociations
            : $this->opportunityAssociations()->get();

        return collect([$primary])
            ->concat($others->map(fn (UserOpportunity $row) => \App\Support\Opportunity::find($row->opportunity))->filter())
            ->unique(fn (\App\Support\Opportunity $o) => $o->key)
            ->values();
    }

    public function hasOpportunity(string $key): bool
    {
        return $this->opportunities()->contains(fn (\App\Support\Opportunity $o) => $o->key === $key);
    }

    /**
     * The sign-up link this partner shares.
     *
     * Carries their own business line, so somebody joining through a free
     * clean-air partner's link is not asked for a card on arrival. Without the
     * parameter the link falls back to the default line — correct for a
     * membership partner, wrong for everybody else, and the failure is silent
     * because the link still works.
     *
     * The same pair is what the marketing sites put on their Join buttons; see
     * sites/_shared/assets/ref.js and OpportunityTracker.
     */
    public function referralJoinUrl(): string
    {
        $line  = $this->opportunity();
        $query = array_filter([
            \App\Services\Opportunities\OpportunityTracker::PARAM      => $line->key,
            \App\Services\Opportunities\OpportunityTracker::SITE_PARAM => $line->site(),
        ]);

        return url('/join/'.$this->referral_code).($query ? '?'.http_build_query($query) : '');
    }

    /**
     * Add a business line to this account.
     *
     * Idempotent — the unique index on (user_id, opportunity) is the guard, and
     * a second attempt updates nothing rather than failing. Making it primary
     * moves the denormalised column too, so the two never disagree.
     */
    public function associateOpportunity(string $key, string $source = 'signup', bool $primary = false, ?self $by = null): void
    {
        if (! \App\Support\Opportunity::exists($key)) {
            return;
        }

        UserOpportunity::query()->updateOrCreate(
            ['user_id' => $this->id, 'opportunity' => $key],
            ['source' => $source, 'added_by_user_id' => $by?->id],
        );

        if ($primary && $this->primary_opportunity !== $key) {
            $this->forceFill(['primary_opportunity' => $key])->save();
        }

        $this->unsetRelation('opportunityAssociations');
    }

    /**
     * Does this member have to carry the membership — and therefore a card?
     *
     * True if ANY of their lines requires it. A PlasmaGuard partner who adds
     * the training program becomes an ordinary paying member from that moment;
     * nothing has to remember that they once were not.
     */
    public function requiresMembership(): bool
    {
        if ($this->isAdmin() || $this->billing_exempt === true) {
            return false;
        }

        return $this->opportunities()->contains(fn ($o) => $o->requiresMembership());
    }

    /**
     * May this member see a given section of the back office?
     *
     * The union of their lines' features. Admins see everything — they are
     * previewing the product, not using it.
     */
    public function canSee(string $feature): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->opportunities()->contains(fn ($o) => $o->allows($feature));
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

    /**
     * When this member's training clock starts — the anchor the drip counts from.
     *
     * The training releases a module a month from the start of the PAID
     * membership, so the anchor has to be the moment billing began, not the
     * moment the account was created. Those are far apart here: a partner can
     * sit on a parked pre-launch trial, or on commission hold, for months
     * before they pay for anything. Counting from signup would hand a partner
     * who has never paid a library they have not bought.
     *
     * In order of preference:
     *
     *  1. `billing_trigger_met_at` — the commission-hold partner's billing
     *     started the day their paid commissions cleared the threshold.
     *  2. `trial_ends_at` — the parked pre-launch trial converts at launch, and
     *     that conversion is month one. While it is still in the future the
     *     anchor is too, which correctly leaves the delayed modules shut.
     *  3. `current_period_start` — an ordinary subscription already billing.
     *  4. `active_start_date` — the hand-set date, kept as a fallback so an
     *     admin can still place a comped or imported member on the schedule.
     *
     * Null means there is no clock: nothing with a delay on it has opened yet.
     */
    public function trainingClockStartedAt(): ?\Carbon\Carbon
    {
        // Their access does not come from a card, so neither does their clock.
        // Anchoring on signup opens everything, which is what an admin or a
        // comped founder should see.
        if ($this->isAdmin() || $this->billing_exempt === true) {
            return $this->active_start_date?->copy()
                ?? $this->created_at?->copy();
        }

        $subscription = $this->activeSubscription();

        if ($subscription) {
            $anchor = $subscription->billing_trigger_met_at
                ?? $subscription->trial_ends_at
                ?? $subscription->current_period_start;

            if ($anchor) {
                return $anchor->copy();
            }
        }

        return $this->active_start_date?->copy();
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
