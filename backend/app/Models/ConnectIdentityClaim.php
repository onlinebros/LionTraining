<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that identifies the real person behind a payout account.
 *
 * Bank-account claims are enforced: one real bank account can back only one
 * partner. Name and date-of-birth claims are recorded but advisory, because an
 * identical name and birthday is a real, if rare, coincidence.
 */
class ConnectIdentityClaim extends Model
{
    public const TYPE_BANK_ACCOUNT = 'bank_account';

    public const TYPE_INDIVIDUAL = 'individual';

    protected $fillable = [
        'user_id',
        'claim_type',
        'claim_hash',
        'unique_hash',
        'source_ref',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stored hash for an identifier.
     *
     * Keyed with the app key rather than a bare sha256, so the digests are
     * useless if this table leaks and cannot be brute-forced against lists of
     * bank fingerprints or names.
     */
    public static function hashFor(string $type, string $identifier): string
    {
        return hash_hmac(
            'sha256',
            $type.'|'.mb_strtolower(trim($identifier)),
            (string) config('app.key'),
        );
    }
}
