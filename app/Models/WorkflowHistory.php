<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workflow transition.
 *
 * WHY THIS IS SEPARATE FROM audit_logs
 *
 * This answers "how did this request move?" — a business question, read by the
 * status timeline and by a requestor looking at their own request.
 *
 * audit_logs answers "who changed what value?" — a compliance question, read
 * during a dispute.
 *
 * Merging them makes the timeline either unreadably detailed or the audit
 * incomplete, and they have different immutability needs.
 *
 * APPEND-ONLY. There is no updated_at column, deliberately: an update timestamp
 * on an immutable record is an invitation to write one.
 */
class WorkflowHistory extends Model
{
    use HasFactory;

    /**
     * The table has created_at but no updated_at.
     *
     * Setting this to null makes Eloquent stop writing updated_at. Without it the
     * docblock above was a claim the code did not honour — every insert failed with
     * "table workflow_histories has no column named updated_at" on the first
     * transition.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'from_stage',
        'to_stage',
        'action',
        'performed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    /**
     * Who performed the transition, if a human did.
     *
     * Nullable because a system transition — "all recommendations are in, so this
     * advanced" — has no actor. Forcing a user id would mean inventing one, and a
     * fabricated actor in an audit trail is worse than an honest null.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function wasAutomatic(): bool
    {
        return $this->performed_by === null;
    }
}
