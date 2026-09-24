<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The IT HOU's consolidation, where the governance route is decided.
 *
 * Its own row rather than a column on the request, so the reasoning survives. A
 * route that appears on a request with no explanation invites "who decided this,
 * and on what basis?" — and the answer would be nowhere.
 */
class RecommendationConsolidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'consolidated_by',
        'summary',
        'governance_route_id',
        'consolidated_at',
    ];

    protected function casts(): array
    {
        return ['consolidated_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function consolidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consolidated_by');
    }

    public function governanceRoute(): BelongsTo
    {
        return $this->belongsTo(GovernanceRoute::class);
    }

    /** Whether the chosen route requires a committee decision. */
    public function requiresCommittee(): bool
    {
        return (bool) $this->governanceRoute?->requires_committee;
    }
}
