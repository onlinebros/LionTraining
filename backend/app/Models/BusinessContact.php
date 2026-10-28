<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone at a business account, and the part they play in the decision.
 * Being a contact gives no access to the microsite; inviting them does.
 *
 * @property int $id
 * @property int $business_account_id
 * @property string $name
 * @property ?string $title
 * @property ?string $email
 * @property ?string $phone
 * @property string $role
 * @property bool $is_primary
 * @property ?string $notes
 */
class BusinessContact extends Model
{
    public const ROLES = [
        'decision_maker' => 'Decision maker',
        'champion' => 'Champion',
        'finance' => 'Finance',
        'facilities' => 'Facilities / HVAC',
        'technical' => 'Technical',
        'other' => 'Other',
    ];

    protected $fillable = ['business_account_id', 'name', 'title', 'email', 'phone', 'role', 'is_primary', 'notes'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<BusinessAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }
}
