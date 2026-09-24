<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The completeness review: what governance assessed, and what it confirmed.
 *
 * WHY THIS EXISTS SEPARATELY FROM THE COLUMNS ON `it_requests`
 *
 * The request holds the PROPOSED tier and classification (what the requestor asked
 * for) and the ASSIGNED ones (what governance confirmed). Those columns record
 * values. This records the ACT — who assessed it, when, and why they changed what
 * they changed.
 *
 * Without it, "why was my request classified as Tier 2 when I proposed Tier 1?" has
 * no answer, and that is the first question a requestor asks.
 */
class CompletenessAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'assessed_by',
        'tier_id',
        'classification_id',
        'reclassification_reason',
        'notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class);
    }

    /**
     * Whether governance changed what the requestor proposed.
     *
     * A question asked on the detail screen, so it is answered here rather than
     * recomputed at each call site — three copies of it would eventually disagree,
     * and the difference would show as a request that reports "no change" while the
     * trail shows a reason for the change.
     */
    public function changedTheProposal(): bool
    {
        $request = $this->request;

        if (! $request) {
            return false;
        }

        return ($request->proposed_tier_id !== null && $request->proposed_tier_id !== $this->tier_id)
            || ($request->proposed_classification_id !== null && $request->proposed_classification_id !== $this->classification_id);
    }
}
