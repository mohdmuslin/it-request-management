<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a stage may take, in business days.
 *
 * A null `tier_id` is the default for every tier; a non-null row overrides it for
 * that tier alone. So the common case needs one row per stage, and a
 * tier-specific target is an addition rather than a rewrite.
 */
class StageDueDay extends Model
{
    use HasFactory;

    protected $fillable = ['workflow_stage_id', 'tier_id', 'business_days'];

    protected function casts(): array
    {
        return ['business_days' => 'integer'];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
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
}
