<?php

namespace App\Models;

use App\Enums\Decision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approval assignment or decision.
 *
 * A chain of these — Project Owner, then Project Sponsor, then governance, then
 * possibly a committee — rather than a single approver column on the request. A
 * single column holds only the current approver, so it loses the history that
 * makes the trail auditable, and a chain cannot be retrofitted onto it.
 */
class ApprovalTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'stage',
        'sequence',
        'approver_id',
        'delegated_from_id',
        'due_at',
        'decision',
        'comments',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'decision' => Decision::class,
        ];
    }

    public function request(): BelongsTo
    {
        // The foreign key is named explicitly because Laravel would otherwise
        // derive `it_request_id` from the ItRequest class name, and the column is
        // `request_id`. Guessing wrong produces a "no such column" error at query
        // time rather than at definition time, so it is worth being explicit on
        // every relationship that points at a request.
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Who the decision actually belonged to, when a delegate acted.
     *
     * Null when the named approver acted themselves. Both this and `approver_id`
     * are stored because recording only the substitute loses who the decision
     * belonged to, and recording only the original loses who clicked.
     */
    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from_id');
    }

    public function isPending(): bool
    {
        return $this->decided_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isPending()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /** Whether a delegation was used for this decision. */
    public function wasDelegated(): bool
    {
        return $this->delegated_from_id !== null;
    }

    public function scopePending($query)
    {
        return $query->whereNull('decided_at');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNull('decided_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }
}
