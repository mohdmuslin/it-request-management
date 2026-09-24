<?php

namespace App\Models;

use App\Services\BusinessCalendar;
use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday, or a half-day.
 *
 * `closes_at` null means closed all day. A time means the office closes early —
 * the pattern on the eve of a major festival — and contributes only the hours
 * before that time.
 */
class Holiday extends Model
{
    protected $fillable = [
        'date',
        'name',
        'closes_at',
        'source',
        'external_id',
        'manually_overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'manually_overridden_at' => 'datetime',
        ];
    }

    /** Whether this holiday closes the whole day. */
    public function isFullDay(): bool
    {
        return $this->closes_at === null;
    }

    /** Whether an administrator has corrected a sourced value. */
    public function isManuallyOverridden(): bool
    {
        return $this->manually_overridden_at !== null;
    }

    /**
     * Whether a sync may write to this row.
     *
     * A manual entry is never the business of a sync, and a corrected sourced row
     * must not be reverted by the next run — otherwise the correction looks like
     * it was never made.
     */
    public function isSyncWritable(): bool
    {
        return $this->source !== 'manual' && ! $this->isManuallyOverridden();
    }

    protected static function booted(): void
    {
        // Any calendar change invalidates computed due dates on open tasks. The
        // recompute is triggered by the caller, not here, so that a bulk import
        // does not trigger it once per row.
        static::saved(fn () => app(BusinessCalendar::class)->forgetHolidays());
        static::deleted(fn () => app(BusinessCalendar::class)->forgetHolidays());
    }
}
