<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reviewing unit's recommendation, at a version.
 *
 * Never updated in place. A revision inserts `version_no + 1` and points
 * `supersedes_id` at the row it replaces, so the committee sees the final position
 * *and* the history that produced it — a unit that moved from "not recommended" to
 * "recommended with conditions" has told the committee something important by
 * doing so.
 *
 * It also means one unit can never overwrite another's work, which FR-008
 * requires and which a single shared recommendation column would guarantee by
 * construction that it did.
 */
class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'review_unit_id',
        'reviewer_id',
        'recommendation',
        'conditions',
        'evidence',
        'version_no',
        'supersedes_id',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'version_no' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function reviewUnit(): BelongsTo
    {
        return $this->belongsTo(ReviewUnit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    /** Whether this is the unit's current position. */
    public function isCurrent(): bool
    {
        return ! $this->supersededBy()->exists();
    }

    public function isRevision(): bool
    {
        return $this->version_no > 1;
    }

    /**
     * The latest recommendation per unit for a request.
     *
     * The list the consolidation screen and the committee see. Built as a
     * correlated subquery rather than by fetching all versions and filtering in
     * PHP, so the database does the work and the result stays correct if the
     * version count grows.
     */
    public function scopeCurrent($query)
    {
        return $query->whereNotExists(function ($sub) {
            $sub->from('recommendations as r2')
                ->whereColumn('r2.supersedes_id', 'recommendations.id');
        });
    }

    public function scopeForRequest($query, int $requestId)
    {
        return $query->where('request_id', $requestId);
    }
}
