<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field's requirement for a tier (BR-003).
 *
 * A null `tier_id` is the fallback for every tier; a non-null row overrides it for that tier
 * alone. Absence of a row means optional — see the migration for why.
 */
class TierFieldRule extends Model
{
    use HasFactory;

    protected $fillable = ['tier_id', 'field', 'requirement'];

    /**
     * The three things a rule can say.
     *
     * Constants rather than an enum because they are stored as short strings compared in a hot
     * path — validation runs on every step of every wizard — and an enum's `->value` indirection
     * buys nothing when there are three cases and one consumer.
     */
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const HIDDEN = 'hidden';

    /** @return array<int, string> */
    public static function requirements(): array
    {
        return [self::REQUIRED, self::OPTIONAL, self::HIDDEN];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    /** Whether this is the fallback for all tiers. */
    public function isDefault(): bool
    {
        return $this->tier_id === null;
    }

    /** For the administration screen, where "no tier" needs a readable name. */
    public function tierLabel(): string
    {
        return $this->tier?->name ?? 'Every tier';
    }
}
