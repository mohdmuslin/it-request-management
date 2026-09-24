<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary transfer of approval authority (FR-014).
 *
 * The current process has no delegation at all, which makes an approver's leave a
 * hard stop for every request waiting on them. This is the single point of failure
 * in the existing flow.
 */
class Delegation extends Model
{
    use HasFactory;

    protected $fillable = [
        'approver_id',
        'delegate_id',
        'created_by',
        'starts_at',
        'ends_at',
        'reason',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** The person who is away. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** The person acting. */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Delegations that are in force right now.
     *
     * The bounds are INCLUSIVE on purpose. A delegation "from Monday to Friday"
     * that ends at Friday 00:00 leaves the delegate with no authority on the last
     * day of the arrangement, which is the day the approver is most likely still
     * away — and it fails in the direction that blocks the request.
     *
     * `starts_at` must be in the PAST or now, so a future arrangement does not
     * grant authority early.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    /** Whether this delegation is in force, evaluated in PHP for a loaded row. */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->starts_at !== null
            && $this->ends_at !== null
            && $this->starts_at->lte(now())
            && $this->ends_at->gte(now());
    }

    /**
     * The delegation in force for an approver, if any.
     *
     * Returns the MOST RECENTLY STARTED when several overlap. Overlapping
     * delegations are not prevented — an approver may extend cover without
     * cancelling an existing arrangement — so this has to have a defined answer
     * rather than picking whichever row the database returned first.
     */
    public static function for(int $approverId): ?self
    {
        return static::query()
            ->active()
            ->where('approver_id', $approverId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The label used in a queue: "Acting for X". */
    public function actingForLabel(): string
    {
        return 'Acting for '.($this->approver?->name ?? 'another approver');
    }
}
