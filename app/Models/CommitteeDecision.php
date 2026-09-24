<?php

namespace App\Models;

use App\Enums\Decision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The IT Investment Committee's decision.
 *
 * Voting, motions and quorum are deliberately absent. The brief's Appendix C lists
 * the committee operating model as an assumption to validate during discovery —
 * whether decisions require voting or only recording. This records a decision and
 * leaves the rest open, which is declared deviation D-4.
 */
class CommitteeDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'recorded_by',
        'decision',
        'conditions',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'decision' => Decision::class,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
