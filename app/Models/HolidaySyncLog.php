<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded holiday sync run.
 *
 * The sync runs from cron, and cron output on this host goes to /dev/null. A sync
 * that fails therefore fails completely silently, and the only symptom appears
 * weeks later as "the due dates are wrong". Recording the outcome here gives it
 * somewhere to be seen.
 */
class HolidaySyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'status',
        'created',
        'updated',
        'skipped_overridden',
        'removed',
        'error',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'created' => 'integer',
            'updated' => 'integer',
            'removed' => 'integer',
        ];
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
