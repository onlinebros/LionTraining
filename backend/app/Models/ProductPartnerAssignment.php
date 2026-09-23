<?php

namespace App\Models;

use App\Support\Vendors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vendor's products, visible to one product partner.
 *
 * The role says what kind of account this is; this says what it can see. A
 * product partner with no assignment sees nothing, which is the safe direction
 * for a grant that hands an outside company sight of our sales.
 */
class ProductPartnerAssignment extends Model
{
    /** Every product this vendor sells, including ones added later. */
    public const ALL_PRODUCTS = '*';

    protected $fillable = ['user_id', 'vendor', 'product_key', 'granted_by_user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function coversAllProducts(): bool
    {
        return $this->product_key === self::ALL_PRODUCTS;
    }

    public function vendorName(): string
    {
        return Vendors::name($this->vendor);
    }

    /**
     * What this grant is called on screen.
     *
     * A product key that is no longer in the registry prints as itself rather
     * than as an empty string — a stale grant should look stale, not blank.
     */
    public function label(): string
    {
        if ($this->coversAllProducts()) {
            return $this->vendorName().' — all products';
        }

        $product = Vendors::product($this->vendor, $this->product_key);

        return $this->vendorName().' — '.($product['name'] ?? $this->product_key);
    }
}
