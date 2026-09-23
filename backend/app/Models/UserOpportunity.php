<?php

namespace App\Models;

use App\Support\Opportunity;
use Illuminate\Database\Eloquent\Model;

/**
 * One member's association with one business line.
 *
 * A join row rather than a pivot on a relation, because it carries its own
 * facts — how the association happened and who made it — and because the thing
 * on the far side is a config entry, not a table.
 *
 * See config/opportunities.php.
 */
class UserOpportunity extends Model
{
    protected $table = 'user_opportunities';

    protected $fillable = [
        'user_id',
        'opportunity',
        'source',
        'added_by_user_id',
    ];

    /** How the association happened. */
    public const SOURCE_SIGNUP = 'signup';
    public const SOURCE_SELF   = 'self';
    public const SOURCE_ADMIN  = 'admin';
    public const SOURCE_IMPORT = 'import';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * The registry entry this row points at, or null if the key has since been
     * retired from config. Callers filter nulls rather than falling back to the
     * default — a stale row must not silently grant the default's features.
     */
    public function definition(): ?Opportunity
    {
        return Opportunity::find($this->opportunity);
    }

    public function name(): string
    {
        return $this->definition()?->name() ?? $this->opportunity;
    }
}
