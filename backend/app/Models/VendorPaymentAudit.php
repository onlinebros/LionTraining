<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vendor's account of one order, as last read with their key.
 *
 * Written only by VendorPaymentAuditor. It records what Stripe says; it never
 * changes an order. Confirming a sale stays with the webhook and the event
 * sync, so a read-only audit cannot raise a commission by mistake.
 *
 * @property list<array<string,mixed>>|null $attempts
 */
class VendorPaymentAudit extends Model
{
    public const VERDICT_PAID              = 'paid';
    public const VERDICT_PAID_NOT_RECORDED = 'paid_not_recorded';
    public const VERDICT_RECORDED_NOT_PAID = 'recorded_not_paid';
    public const VERDICT_DECLINED          = 'declined';
    public const VERDICT_ABANDONED         = 'abandoned';
    public const VERDICT_AWAITING_CUSTOMER = 'awaiting_customer';
    public const VERDICT_PROCESSING        = 'processing';
    public const VERDICT_NOT_SUBMITTED     = 'not_submitted';
    public const VERDICT_CANCELED          = 'canceled';
    public const VERDICT_UNREADABLE        = 'unreadable';

    /**
     * Label, badge colour and what an admin should do, per verdict. The first
     * two are where our records and Stripe disagree, which is money.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const VERDICTS = [
        self::VERDICT_PAID_NOT_RECORDED => ['Paid, not recorded', 'danger',
            'Stripe took the money but the order is not confirmed here. Run event recovery, or confirm by hand.'],
        self::VERDICT_RECORDED_NOT_PAID => ['Recorded, not paid', 'danger',
            'Confirmed here but Stripe shows no successful payment. Check before invoicing or paying commission.'],
        self::VERDICT_DECLINED          => ['Declined', 'warning',
            'The card issuer or lender refused it. The buyer needs another card or to call their bank.'],
        self::VERDICT_ABANDONED         => ['Abandoned', 'warning',
            'The buyer was sent to a payment provider (e.g. Affirm) and cancelled or let it expire.'],
        self::VERDICT_AWAITING_CUSTOMER => ['Awaiting customer', 'info',
            'Waiting on the buyer to finish a bank check or redirect.'],
        self::VERDICT_PROCESSING        => ['Processing', 'info',
            'Payment submitted and still clearing (bank debits can take days).'],
        self::VERDICT_NOT_SUBMITTED     => ['Never submitted', 'secondary',
            'The order reached payment but the buyer never pressed Pay, or left the page.'],
        self::VERDICT_CANCELED          => ['Canceled', 'secondary',
            'The payment was cancelled on Stripe.'],
        self::VERDICT_UNREADABLE        => ['Could not read', 'dark',
            'Stripe would not return this payment with the key we hold. See the error.'],
        self::VERDICT_PAID              => ['Paid', 'success',
            'Paid on Stripe and confirmed here.'],
    ];

    /** Verdicts where something needs a person. */
    public const NEEDS_ATTENTION = [
        self::VERDICT_PAID_NOT_RECORDED,
        self::VERDICT_RECORDED_NOT_PAID,
        self::VERDICT_DECLINED,
        self::VERDICT_ABANDONED,
        self::VERDICT_UNREADABLE,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts'          => 'array',
            'intent_amount'     => 'integer',
            'intent_created_at' => 'datetime',
            'checked_at'        => 'datetime',
        ];
    }

    /** @return BelongsTo<VendorLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(VendorLead::class, 'vendor_lead_id');
    }

    public function label(): string
    {
        return self::VERDICTS[$this->verdict][0] ?? $this->verdict;
    }

    public function badge(): string
    {
        return self::VERDICTS[$this->verdict][1] ?? 'secondary';
    }

    public function advice(): string
    {
        return self::VERDICTS[$this->verdict][2] ?? '';
    }

    /** The clearest single sentence on why it failed: Stripe's, then the error's. */
    public function reason(): ?string
    {
        return $this->seller_message ?: $this->failure_message;
    }

    /** Affirm, Klarna, card — how the buyer tried to pay. */
    public function methodLabel(): ?string
    {
        return self::methodName($this->payment_method_type);
    }

    public static function methodName(?string $type): ?string
    {
        return match ($type) {
            null, ''     => null,
            'card'       => 'Card',
            'link'       => 'Link',
            'affirm'     => 'Affirm',
            'klarna'     => 'Klarna',
            'cashapp'    => 'Cash App',
            'amazon_pay' => 'Amazon Pay',
            'us_bank_account' => 'Bank account',
            default      => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
