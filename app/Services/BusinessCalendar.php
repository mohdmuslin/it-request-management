<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Business-time arithmetic.
 *
 * WHY THIS CLASS EXISTS
 *
 * Every due date in the system is a business-time calculation, and business time
 * is not clock time. "Three days" means three working days — weekdays, excluding
 * the lunch break, excluding public holidays. Getting this wrong is not a
 * cosmetic error: a due date that expires over a weekend generates an escalation
 * for something nobody could have acted on.
 *
 * THE ARITHMETIC, STATED EXPLICITLY
 *
 * The shift spans 09:00-18:00 with an unpaid hour at 13:00. So the working
 * intervals are:
 *
 *     09:00 - 13:00   four hours
 *     14:00 - 18:00   four hours
 *                    -----------
 *                     eight hours
 *
 * Eight divides cleanly, which is why a 24-working-hour target is exactly three
 * business days rather than two and a bit.
 *
 * A half-day holiday intersects those intervals with the day's closing time, so a
 * holiday that closes at 13:00 contributes the morning block only.
 *
 * NO DAYLIGHT SAVING
 *
 * Malaysia is a fixed UTC+8 with no DST, so unlike most business-calendar code
 * there is no transition to handle. That is worth stating so nobody adds handling
 * for a case that cannot occur.
 */
class BusinessCalendar
{
    private string $timezone;

    /** @var array<int, array{0: string, 1: string}> */
    private array $breaks;

    private string $opensAt;

    private string $closesAt;

    /** @var array<int, int> */
    private array $days;

    /** @var Collection<int, Holiday>|null */
    private ?Collection $holidays = null;

    public function __construct()
    {
        $config = config('itrequest.business_hours');

        $this->timezone = $config['timezone'];
        $this->days = $config['days'];
        $this->opensAt = $config['opens_at'];
        $this->closesAt = $config['closes_at'];
        $this->breaks = $config['breaks'];
    }

    /**
     * Working intervals for a given date, in local time.
     *
     * Returns an empty array for a closed day, which is the honest representation:
     * the day contributes no working time, and callers need not special-case it.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function intervalsFor(CarbonInterface $date): array
    {
        $day = CarbonImmutable::instance($date)->setTimezone($this->timezone)->startOfDay();

        // Not a working day of the week.
        if (! in_array($day->dayOfWeek, $this->days, true)) {
            return [];
        }

        $holiday = $this->holidayOn($day);

        // Fully closed: no intervals at all.
        if ($holiday && $holiday->closes_at === null) {
            return [];
        }

        // The day's end, which a half-day holiday moves earlier.
        $dayEnd = $holiday
            ? $day->setTimeFromTimeString($holiday->closes_at)
            : $day->setTimeFromTimeString($this->closesAt);

        $dayStart = $day->setTimeFromTimeString($this->opensAt);

        // Build the full open span, then subtract breaks.
        $segments = [[$dayStart, $dayEnd]];

        foreach ($this->breaks as [$breakStart, $breakEnd]) {
            $segments = $this->subtractBreak(
                $segments,
                $day->setTimeFromTimeString($breakStart),
                $day->setTimeFromTimeString($breakEnd),
            );
        }

        // Drop anything a half-day holiday has pushed to zero or negative length.
        return array_values(array_filter(
            $segments,
            fn (array $s) => $s[1]->greaterThan($s[0])
        ));
    }

    /**
     * Add a number of working hours to a moment.
     *
     * Walks forward interval by interval, consuming the remaining hours. The
     * iteration is bounded by a generous ceiling so a misconfiguration cannot spin
     * forever — an infinite loop in a request handler is worse than a wrong date.
     *
     * @param  float  $hours  Working hours to add. Fractional values are supported.
     */
    public function addWorkingHours(CarbonInterface $from, float $hours): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $remaining = $hours;
        $guard = 0;

        while ($remaining > 0) {
            if (++$guard > 3660) {
                // Ten years of daily intervals. Reaching this means the calendar is
                // broken — every day closed forever — not that the request is large.
                throw new \RuntimeException(
                    'BusinessCalendar could not find any working time within ten years. '
                    .'Check the business hours configuration and the holiday table.'
                );
            }

            $intervals = $this->intervalsFor($cursor);

            foreach ($intervals as [$start, $end]) {
                // Skip intervals that have already passed.
                if ($end->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                // If we are mid-interval, start from now rather than the interval start.
                $effectiveStart = $start->greaterThan($cursor) ? $start : $cursor;
                $available = $effectiveStart->diffInMinutes($end) / 60;

                if ($remaining <= $available) {
                    return $effectiveStart->addMinutes((int) round($remaining * 60));
                }

                $remaining -= $available;
                $cursor = $end;
            }

            // Nothing left today; move to the next day.
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $cursor;
    }

    /**
     * Add a number of business days to a moment.
     *
     * WHAT "ONE BUSINESS DAY" MEANS, AND WHY IT IS NOT "EIGHT HOURS"
     *
     * An earlier implementation multiplied the day count by the hours in a day and
     * reused addWorkingHours. That is wrong: eight working hours from Thursday
     * 09:00 completes on Thursday itself, so "one business day from Thursday"
     * returned Thursday. A business user would say that is due Friday.
     *
     * This advances whole working days and preserves the time of day, so:
     *
     *     Thursday  + 1 business day  ->  Friday
     *     Thursday  + 2               ->  Monday   (weekend skipped)
     *     Friday    + 1               ->  Monday
     *     Thursday  + 1, Friday closed -> Monday   (holiday skipped)
     *
     * That matches how "three days to approve this" is actually spoken.
     * addWorkingHours remains available for hour-precision, and both are used —
     * this for stage targets, that for the aging reports.
     */
    public function addBusinessDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);

        // Anchor inside working hours first, so a late-evening or lunchtime start
        // does not preserve a time of day that the calendar considers closed.
        $cursor = $this->clampIntoWorkingIntervals($cursor, moveForward: true);

        for ($i = 0; $i < $days; $i++) {
            $cursor = $this->nextWorkingDayPreservingTime($cursor);
        }

        return $cursor;
    }

    /**
     * The next working day, at or near the same time of day.
     *
     * Advances a day at a time until the date is a working one, then pulls the time
     * into that day's open intervals. A time that falls in the lunch break moves to
     * the afternoon; a time after closing moves to the end of the day, because the
     * deadline belongs to the day the target lands on rather than spilling into the
     * next one.
     */
    private function nextWorkingDayPreservingTime(CarbonImmutable $moment): CarbonImmutable
    {
        $candidate = $moment->addDay();
        $guard = 0;

        while (! $this->isWorkingDay($candidate)) {
            if (++$guard > 366) {
                throw new \RuntimeException(
                    'BusinessCalendar found no working day within a year. '
                    .'Check the business hours configuration and the holiday table.'
                );
            }

            $candidate = $candidate->addDay();
        }

        return $this->clampIntoWorkingIntervals($candidate);
    }

    /**
     * Pull a moment into the working intervals of its own day.
     *
     * Three cases: before the day opens, inside a closed interval, or after it
     * closes. Each has an obvious answer, and getting the middle one wrong is how a
     * deadline lands mid-lunch-break.
     */
    private function clampIntoWorkingIntervals(CarbonImmutable $moment, bool $moveForward = false): CarbonImmutable
    {
        $intervals = $this->intervalsFor($moment);

        if ($intervals === []) {
            return $moment;
        }

        $opens = $intervals[0][0];
        $closes = $intervals[count($intervals) - 1][1];

        if ($moment->lessThan($opens)) {
            return $opens;
        }

        // At or past closing: stay on this day rather than spilling forward, so the
        // deadline belongs to the day it was calculated for.
        if ($moment->greaterThanOrEqualTo($closes)) {
            return $closes;
        }

        foreach ($intervals as [$start, $end]) {
            if ($moment->greaterThanOrEqualTo($start) && $moment->lessThan($end)) {
                return $moment;
            }
        }

        // Inside a break. Move to the start of the next interval, which is what a
        // person would expect rather than being sent home for lunch.
        foreach ($intervals as [$start]) {
            if ($start->greaterThan($moment)) {
                return $start;
            }
        }

        return $closes;
    }

    /**
     * Count working hours between two moments.
     *
     * Used by the aging reports, and by tests that assert an interval spans a
     * break, a weekend or a holiday correctly.
     */
    public function workingHoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $start = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $end = CarbonImmutable::instance($to)->setTimezone($this->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $total = 0.0;
        $cursor = $start->startOfDay();

        foreach (CarbonPeriod::create($cursor, $end->startOfDay()) as $day) {
            foreach ($this->intervalsFor($day) as [$open, $close]) {
                $overlapStart = $open->greaterThan($start) ? $open : $start;
                $overlapEnd = $close->lessThan($end) ? $close : $end;

                if ($overlapEnd->greaterThan($overlapStart)) {
                    $total += $overlapStart->diffInMinutes($overlapEnd) / 60;
                }
            }
        }

        return $total;
    }

    /** Whether the given date is a working day, ignoring anything else. */
    public function isWorkingDay(CarbonInterface $date): bool
    {
        return $this->intervalsFor($date) !== [];
    }

    /** Total working hours in one standard day. */
    public function hoursPerDay(): float
    {
        $day = CarbonImmutable::now($this->timezone)->startOfDay();

        // Use a known-good weekday. A configuration that closes every configured
        // day would return zero here and silently produce instantly-overdue tasks,
        // so fall back to computing from the raw span rather than returning 0.
        for ($i = 0; $i < 7; $i++) {
            $candidate = $day->addDays($i);
            $hours = collect($this->intervalsFor($candidate))
                ->sum(fn (array $s) => $s[0]->diffInMinutes($s[1]) / 60);

            if ($hours > 0) {
                return (float) $hours;
            }
        }

        return 0.0;
    }

    /** Forget the cached holidays; used after a calendar change. */
    public function forgetHolidays(): void
    {
        $this->holidays = null;
    }

    /**
     * Subtract a break from a set of intervals.
     *
     * A break can fall entirely outside an interval, split it in two, or clip one
     * end. All three cases are handled here rather than at the call site, because
     * the split case is the one that is easy to miss and produces a lunch hour
     * that counts as worked time.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $segments
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtractBreak(array $segments, CarbonImmutable $breakStart, CarbonImmutable $breakEnd): array
    {
        $result = [];

        foreach ($segments as [$start, $end]) {
            // No overlap: keep the segment untouched.
            if ($breakEnd->lessThanOrEqualTo($start) || $breakStart->greaterThanOrEqualTo($end)) {
                $result[] = [$start, $end];

                continue;
            }

            // Keep the part before the break.
            if ($breakStart->greaterThan($start)) {
                $result[] = [$start, $breakStart];
            }

            // Keep the part after the break.
            if ($breakEnd->lessThan($end)) {
                $result[] = [$breakEnd, $end];
            }
        }

        return $result;
    }

    private function holidayOn(CarbonImmutable $day): ?Holiday
    {
        $this->holidays ??= Holiday::query()->get()->keyBy(
            fn (Holiday $h) => $h->date->toDateString()
        );

        return $this->holidays->get($day->toDateString());
    }
}
