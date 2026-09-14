<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which account first saved a physical card, kept for good.
 *
 * `payment_methods` mirrors the cards an account holds right now, so its rows
 * disappear when a card is removed or an account is deleted. This ledger does
 * not: once a card has backed one account it cannot back another. `user_id`
 * goes null if that account is deleted, and the card stays claimed.
 */
class CardFingerprint extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Is this card claimed by, or currently saved on, an account other than this one? */
    public static function isHeldByAnother(string $fingerprint, User $user): bool
    {
        $claim = static::where('fingerprint', $fingerprint)->first();

        if ($claim !== null) {
            return $claim->user_id === null || (int) $claim->user_id !== (int) $user->id;
        }

        // Cards saved before this ledger existed.
        return PaymentMethod::where('fingerprint', $fingerprint)
            ->where('user_id', '!=', $user->id)
            ->exists();
    }

    /**
     * Claim the card for this account. False when another account holds it.
     *
     * insertOrIgnore compiles to ON CONFLICT DO NOTHING, the only form that is
     * safe inside a transaction on Postgres: a plain insert that hits the unique
     * index aborts the whole transaction. Under a race the second writer waits
     * for the first to commit, inserts nothing, and reads the winner back.
     */
    public static function claim(string $fingerprint, User $user): bool
    {
        static::query()->insertOrIgnore([
            'fingerprint'   => $fingerprint,
            'user_id'       => $user->id,
            'first_seen_at' => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return (int) static::where('fingerprint', $fingerprint)->value('user_id') === (int) $user->id;
    }
}
