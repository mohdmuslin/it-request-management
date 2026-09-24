<?php

namespace App\Models;

use App\Services\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
            'manually_overridden_at' => 'datetime',
        ];
    }

    /**
     * The date, stored as `Y-m-d`.
     *
     * WHY THIS IS A MUTATOR RATHER THAN A `date` CAST
     *
     * Laravel's `date` cast serialises through `fromDateTime()`, which writes
     * "2026-12-25 00:00:00" into the column. MySQL's DATE type truncates that back to
     * "2026-12-25", so everything works there — and SQLite stores the whole string.
     *
     * The result is a bug that only appears on one of the two drivers: the
     * `unique:holidays,date` validation rule compares against "2026-12-25", finds no
     * match in a column holding "2026-12-25 00:00:00", passes — and then the database
     * rejects the insert. The user sees a database error instead of "that date is
     * already in the calendar".
     *
     * Writing the date portion explicitly makes the stored value identical on both
     * drivers, so the validation, the unique index and any `where('date', ...)` query
     * all agree.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : CarbonImmutable::parse($value),
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString(),
        );
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
