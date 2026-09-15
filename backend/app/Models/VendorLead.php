<?php

namespace App\Models;

use App\Services\Vendor\Shipping\AddressVerification;
use App\Support\Vendors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer a partner sourced for a third-party vendor.
 *
 * This is the local record of a sale we do not process. It is written BEFORE
 * the customer is handed to the vendor's checkout, which is the whole point:
 * attribution must survive the vendor telling us nothing at all.
 *
 * @property array<string, string|null>|null $address_suggestion  FedEx's corrected address, while the buyer decides.
 */
class VendorLead extends Model
{
    use SoftDeletes;

    public const STATUS_NEW        = 'new';
    public const STATUS_HANDED_OFF = 'handed_off';
    public const STATUS_CONVERTED  = 'converted';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_LOST       = 'lost';

    public const VIA_WEBHOOK        = 'webhook';
    public const VIA_MANUAL         = 'manual';
    public const VIA_IMPORT         = 'import';
    public const VIA_RECONCILIATION = 'reconciliation';

    /** A partner ordering for themselves from the member area. */
    public const SOURCE_BACK_OFFICE = 'back_office';

    // Who a sale counts for and who is paid. See PurchaseAttribution.
    public const ATTRIBUTION_CUSTOMER = 'customer';  // sold to a customer through a partner's link
    public const ATTRIBUTION_SELF     = 'self';      // a partner's own purchase; commission to their sponsor
    public const ATTRIBUTION_REVIEW   = 'review';    // matches a partner on address only; commission held

    /*
     * The attribution columns (attribution, buyer_user_id, credited_member_id,
     * earner_id, attribution_resolved_*) are deliberately not fillable. They
     * decide who is paid, and are only ever written by PurchaseAttribution.
     *
     * The address check columns (address_status and the rest) are not fillable
     * either: they decide whether payment opens, and are only written by
     * AddressCheck.
     */
    protected $fillable = [
        'public_ref', 'vendor', 'product_key',
        'member_id', 'referral_code', 'crm_contact_id',
        'first_name', 'last_name', 'email', 'phone', 'company',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'quantity', 'notes', 'qualifiers',
        'subtotal_amount', 'shipping_amount', 'handling_amount', 'tax_amount',
        'our_share_amount', 'invoiced_at', 'invoice_reference', 'settled_at',
        'stripe_charge_id', 'stripe_receipt_url', 'tax_calculation_id',
        'shipping_rate_source', 'shipping_service', 'carrier', 'tracking_number', 'shipped_at',
        'status', 'handed_off_at', 'checkout_url',
        'vendor_order_ref', 'provider_session_id', 'provider_payment_intent_id',
        'amount_total', 'currency', 'converted_at', 'refunded_at',
        'confirmed_via', 'confirmed_by', 'commission_ledger_id',
        'source', 'ip', 'user_agent', 'utm',
    ];

    protected function casts(): array
    {
        return [
            'qualifiers'    => 'array',
            'utm'           => 'array',
            'quantity'      => 'integer',
            'amount_total'  => 'integer',
            'subtotal_amount'  => 'integer',
            'shipping_amount'  => 'integer',
            'handling_amount'  => 'integer',
            'tax_amount'       => 'integer',
            'our_share_amount' => 'integer',
            'invoiced_at'      => 'datetime',
            'settled_at'       => 'datetime',
            'shipped_at'       => 'datetime',
            'handed_off_at' => 'datetime',
            'converted_at'  => 'datetime',
            'refunded_at'   => 'datetime',
            'attribution_resolved_at' => 'datetime',
            'address_suggestion'   => 'array',
            'address_checked_at'   => 'datetime',
            'address_confirmed_at' => 'datetime',
            'address_reviewed_at'  => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The partner whose share link was used.
     *
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * The partner who bought for themselves, when one did.
     *
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /**
     * Who the sale counts for, in their sales record and in promotions.
     *
     * @return BelongsTo<User, $this>
     */
    public function creditedMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_member_id');
    }

    /**
     * Who the commission is paid to.
     *
     * @return BelongsTo<User, $this>
     */
    public function earner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function attributionResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attribution_resolved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function addressReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'address_reviewed_by');
    }

    /** @return BelongsTo<CrmContact, $this> */
    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The one credit raised for this sale, if it has been.
     *
     * @return BelongsTo<CommissionLedger, $this>
     */
    public function commissionCredit(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class, 'commission_ledger_id');
    }

    /**
     * The commission credit raised for this sale.
     *
     * The inverse side is CommissionLedger's polymorphic `source`, which already
     * existed — a converted vendor lead is exactly the qualifying revenue event
     * the compensation engine was built to pay against.
     *
     * @return MorphMany<CommissionLedger, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(CommissionLedger::class, 'source');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForVendor($query, string $vendor)
    {
        return $query->where('vendor', $vendor);
    }

    public function scopeAwaitingPurchase($query)
    {
        return $query->where('status', self::STATUS_HANDED_OFF);
    }

    public function scopeConverted($query)
    {
        return $query->where('status', self::STATUS_CONVERTED);
    }

    /** Sold, our share earned, and not yet invoiced to the vendor. */
    public function scopeUninvoiced($query)
    {
        return $query->where('status', self::STATUS_CONVERTED)
            ->whereNull('invoiced_at')
            ->where('our_share_amount', '>', 0);
    }

    /**
     * Converted, someone is due commission, and none has been raised — the
     * reconciliation worklist.
     *
     * Orders with no earner are left off: an own purchase by a partner with no
     * sponsor pays no one by design, and a held order is on its own list.
     */
    public function scopeUncommissioned($query)
    {
        return $query->where('status', self::STATUS_CONVERTED)
            ->whereNotNull('earner_id')
            ->whereNull('commission_ledger_id');
    }

    /** Matched a partner on address alone; waiting for an admin to decide. */
    public function scopeNeedsAttributionReview($query)
    {
        return $query->where('attribution', self::ATTRIBUTION_REVIEW);
    }

    /**
     * The buyer confirmed an address FedEx did not, and no admin has checked it.
     *
     * Named differently from needsAddressReview() on purpose: a static call to a
     * scope that shares a name with an instance method reaches the method, not
     * the query.
     */
    public function scopeAddressAwaitingReview($query)
    {
        return $query->whereNotNull('address_confirmed_at')
            ->where('address_status', '!=', AddressVerification::VERIFIED)
            ->whereNull('address_reviewed_at');
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isOwnPurchase(): bool
    {
        return $this->attribution === self::ATTRIBUTION_SELF;
    }

    /**
     * May the buyer pay?
     *
     * Only once FedEx has verified the address, or the buyer has confirmed one
     * FedEx could not. Never for an address FedEx cannot deliver to.
     */
    public function addressReadyForPayment(): bool
    {
        return match ($this->address_status) {
            AddressVerification::VERIFIED => true,
            AddressVerification::SUGGESTED,
            AddressVerification::UNVERIFIED,
            AddressVerification::UNAVAILABLE => $this->address_confirmed_at !== null,
            default => false,
        };
    }

    public function needsAddressReview(): bool
    {
        return $this->address_confirmed_at !== null
            && $this->address_status !== AddressVerification::VERIFIED
            && $this->address_reviewed_at === null;
    }

    /**
     * A short label for the address check, and its badge colour.
     *
     * @return array{0: string, 1: string}
     */
    public function addressCheckLabel(): array
    {
        if ($this->address_confirmed_at !== null && $this->address_status !== AddressVerification::VERIFIED) {
            return $this->address_reviewed_at !== null
                ? ['Buyer confirmed; checked by admin', 'success']
                : ['Buyer confirmed; not verified', 'warning'];
        }

        return match ($this->address_status) {
            AddressVerification::VERIFIED    => ['Verified by FedEx', 'success'],
            AddressVerification::SUGGESTED   => ['FedEx suggested a correction', 'info'],
            AddressVerification::UNVERIFIED  => ['Not verified', 'warning'],
            AddressVerification::UNAVAILABLE => ['Not checked (FedEx unavailable)', 'secondary'],
            AddressVerification::REJECTED    => ['Undeliverable (PO Box)', 'danger'],
            default                          => ['Not checked', 'secondary'],
        };
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.(string) $this->last_name);
    }

    public function vendorName(): string
    {
        return Vendors::name((string) $this->vendor);
    }

    public function productName(): string
    {
        $product = Vendors::product((string) $this->vendor, (string) $this->product_key);

        return (string) ($product['name'] ?? $this->product_key);
    }

    /** The vendor-confirmed total as a decimal string, or null before conversion. */
    public function amountDecimal(): ?string
    {
        return $this->amount_total === null
            ? null
            : number_format($this->amount_total / 100, 2, '.', '');
    }
}
