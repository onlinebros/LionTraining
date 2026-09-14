<?php

namespace App\Models;

use App\Support\Vendors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer a partner sourced for a third-party vendor.
 *
 * This is the local record of a sale we do not process. It is written BEFORE
 * the customer is handed to the vendor's checkout, which is the whole point:
 * attribution must survive the vendor telling us nothing at all.
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
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function member()
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function crmContact()
    {
        return $this->belongsTo(CrmContact::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The commission credit raised for this sale.
     *
     * The inverse side is CommissionLedger's polymorphic `source`, which already
     * existed — a converted vendor lead is exactly the qualifying revenue event
     * the compensation engine was built to pay against.
     */
    public function ledgerEntries()
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

    /** Converted but with no commission raised — the reconciliation worklist. */
    public function scopeUncommissioned($query)
    {
        return $query->where('status', self::STATUS_CONVERTED)
            ->whereNull('commission_ledger_id');
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
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
