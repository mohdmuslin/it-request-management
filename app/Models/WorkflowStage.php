<?php

namespace App\Models;

use App\Enums\WorkflowStage as WorkflowStageEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A step in the process definition.
 *
 * Separate from `stage_due_days` on purpose: a stage exists whether or not anybody
 * has agreed how long it may take. Merging them would mean a stage cannot be
 * defined until a target is agreed — which is precisely the position the current
 * process is in, with no due dates anywhere.
 */
class WorkflowStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'sort_order', 'is_approval', 'is_governance', 'is_committee',
    ];

    protected function casts(): array
    {
        return [
            'is_approval' => 'boolean',
            'is_governance' => 'boolean',
            'is_committee' => 'boolean',
        ];
    }

    public function dueDays(): HasMany
    {
        return $this->hasMany(StageDueDay::class);
    }

    /**
     * The configured target for this stage, in business days.
     *
     * Prefers a tier-specific override and falls back to the default row. Returns
     * null when no target is configured, which is a legitimate state — the request
     * simply has no due date, and the aging report says so rather than inventing
     * one.
     */
    public function businessDaysFor(?Tier $tier = null): ?int
    {
        $this->loadMissing('dueDays');

        if ($tier) {
            $override = $this->dueDays->firstWhere('tier_id', $tier->id);

            if ($override) {
                return $override->business_days;
            }
        }

        return $this->dueDays->firstWhere('tier_id', null)?->business_days;
    }

    public function enum(): ?WorkflowStageEnum
    {
        return WorkflowStageEnum::tryFrom($this->code);
    }
}
