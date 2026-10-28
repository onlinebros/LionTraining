<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone invited to a prospect's portal (owner decision D3: invited emails
 * with a sign-in link). The link is reusable until it expires, because mail
 * scanners open links before people do and a single-use link would be spent
 * by the scanner.
 *
 * @property int $id
 * @property int $prospect_id
 * @property string $email
 * @property ?string $name
 * @property ?Carbon $token_expires_at
 * @property ?Carbon $sent_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $revoked_at
 */
class PortalInvite extends Model
{
    public const LINK_DAYS = 14;

    protected $fillable = ['prospect_id', 'email', 'name', 'created_by'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Prospect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** A fresh sign-in token. Only its hash is kept; the token goes in the email. */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addDays(self::LINK_DAYS),
            'sent_at' => now(),
        ])->save();

        return $token;
    }

    public static function findByToken(Prospect $prospect, string $token): ?self
    {
        return self::where('prospect_id', $prospect->id)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('token_expires_at', '>', now())
            ->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
