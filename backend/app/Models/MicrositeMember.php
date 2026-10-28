<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone at a business account who may open its microsite (owner,
 * 2026-10-28: we invite people, and anyone invited may ask for a colleague,
 * whom an admin approves before a link is sent). A viewer reads; a
 * contributor may also comment. Pricing is shown only to those allowed.
 *
 * The sign-in link is reusable until it expires, because mail scanners open
 * links before people do (as PortalInvite).
 *
 * @property int $id
 * @property int $business_account_id
 * @property string $email
 * @property ?string $name
 * @property string $role
 * @property bool $can_see_pricing
 * @property string $status
 * @property ?int $requested_by_member_id
 * @property ?string $request_note
 * @property ?Carbon $token_expires_at
 * @property ?Carbon $sent_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $revoked_at
 */
class MicrositeMember extends Model
{
    public const LINK_DAYS = 14;

    public const ROLES = [
        'viewer' => 'Viewer',
        'contributor' => 'Contributor',
    ];

    protected $fillable = [
        'business_account_id', 'email', 'name', 'role', 'can_see_pricing',
        'status', 'requested_by_member_id', 'request_note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'can_see_pricing' => 'boolean',
            'token_expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /** @return BelongsTo<self, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'requested_by_member_id');
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

    public static function findByToken(BusinessAccount $account, string $token): ?self
    {
        return self::where('business_account_id', $account->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'invited')
            ->whereNull('revoked_at')
            ->where('token_expires_at', '>', now())
            ->first();
    }

    /** Invited, approved and not removed: may open the site. */
    public function isActive(): bool
    {
        return $this->status === 'invited' && $this->revoked_at === null;
    }

    public function isPendingRequest(): bool
    {
        return $this->status === 'requested' && $this->revoked_at === null;
    }

    public function canComment(): bool
    {
        return $this->isActive() && $this->role === 'contributor';
    }

    public function label(): string
    {
        return $this->name ?: $this->email;
    }
}
