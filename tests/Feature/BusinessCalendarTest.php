<?php

use App\Models\Holiday;
use App\Services\BusinessCalendar;
use Carbon\CarbonImmutable;

/**
 * Business-time arithmetic.
 *
 * These tests exist because a wrong due date does not announce itself. A target
 * that expires over a weekend, or counts the lunch hour as worked time, produces
 * an escalation for something nobody could have acted on — and the symptom appears
 * weeks later as "the alerts are wrong", with nothing pointing back at the
 * calendar.
 *
 * The configured day is 09:00-18:00 with an unpaid hour at 13:00, so the working
 * intervals are 09:00-13:00 and 14:00-18:00: two four-hour blocks, eight hours.
 * Eight divides cleanly, which is why a 24-working-hour target is exactly three
 * business days.
 *
 * 2026-09-24 is a Thursday. That matters for every expectation below.
 */
beforeEach(function () {
    $this->calendar = app(BusinessCalendar::class);
});

it('reports exactly eight working hours in a standard day', function () {
    // Four before the break, four after. If the break were lost this would be nine,
    // and every target in the system would be silently short by an hour a day.
    expect($this->calendar->hoursPerDay())->toBe(8.0);
});

it('produces two intervals on a working day, split by the lunch break', function () {
    $thursday = CarbonImmutable::parse('2026-09-24 00:00:00', 'Asia/Kuala_Lumpur');
    $intervals = $this->calendar->intervalsFor($thursday);

    expect($intervals)->toHaveCount(2);

    // Morning runs to 13:00, not 12:00 — the break is 13:00-14:00, so the morning
    // block is four hours, not three.
    expect($intervals[0][0]->format('H:i'))->toBe('09:00')
        ->and($intervals[0][1]->format('H:i'))->toBe('13:00')
        ->and($intervals[1][0]->format('H:i'))->toBe('14:00')
        ->and($intervals[1][1]->format('H:i'))->toBe('18:00');
});

it('counts eight working hours across the lunch break', function () {
    $start = CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Kuala_Lumpur');
    $end = CarbonImmutable::parse('2026-09-24 18:00:00', 'Asia/Kuala_Lumpur');

    // The clock span is nine hours but the worked time is eight. Conflating the two
    // is the error this test is here to catch.
    expect($this->calendar->workingHoursBetween($start, $end))->toBe(8.0);
});

it('adds eight working hours to land at 18:00, having excluded the break', function () {
    $start = CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Kuala_Lumpur');
    $due = $this->calendar->addWorkingHours($start, 8);

    // Eight WORKING hours from 09:00 is 18:00, because the lunch hour is not
    // worked and so is not counted. The clock span is nine hours; the worked time
    // is eight. Landing on 17:00 would mean the break had been charged as work.
    expect($due->format('Y-m-d H:i'))->toBe('2026-09-24 18:00');
});

it('does not count the lunch break as working time when adding hours', function () {
    $start = CarbonImmutable::parse('2026-09-24 12:00:00', 'Asia/Kuala_Lumpur');
    $due = $this->calendar->addWorkingHours($start, 2);

    // One hour to the break at 13:00, then the break is skipped entirely, then the
    // remaining hour is worked from 14:00. Result: 15:00.
    expect($due->format('Y-m-d H:i'))->toBe('2026-09-24 15:00');
});

it('skips the weekend when adding business days', function () {
    // Friday.
    $friday = CarbonImmutable::parse('2026-09-25 09:00:00', 'Asia/Kuala_Lumpur');

    /*
     * THE CONVENTION THIS LOCKS IN, WHICH THE BUSINESS SHOULD CONFIRM
     *
     * "N business days" is treated as a DURATION of N full working days, not as a
     * count that includes today. So one business day from Friday is Monday, not
     * Friday.
     *
     * Worked through: Thursday + 3 gives Friday, Monday, Tuesday — three working
     * days have elapsed, and the target falls on the Tuesday.
     *
     * A calendar-day calculation would land on Saturday, and an inclusive count
     * would land a day earlier throughout. Both would be wrong in the same
     * direction on every request, which is why this is worth a test rather than a
     * comment.
     */
    expect($this->calendar->addBusinessDays($friday, 1)->format('Y-m-d'))->toBe('2026-09-28')
        ->and($this->calendar->addBusinessDays($friday, 2)->format('Y-m-d'))->toBe('2026-09-29');

    // Thursday + 3 skips the weekend: Friday, Monday, then Tuesday.
    $thursday = CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Kuala_Lumpur');

    expect($this->calendar->addBusinessDays($thursday, 3)->format('Y-m-d'))->toBe('2026-09-29');
});

it('treats a Saturday as non-working', function () {
    $saturday = CarbonImmutable::parse('2026-09-26 00:00:00', 'Asia/Kuala_Lumpur');

    expect($this->calendar->isWorkingDay($saturday))->toBeFalse()
        ->and($this->calendar->intervalsFor($saturday))->toBe([]);
});

it('skips a public holiday when adding business days', function () {
    Holiday::create([
        'date' => '2026-09-25',
        'name' => 'Test public holiday',
        'source' => 'manual',
    ]);

    $this->calendar->forgetHolidays();

    // Friday 25 Sep is now closed, so one business day from Thursday is Monday the
    // 28th. Without the holiday it would be the Friday.
    $thursday = CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Kuala_Lumpur');

    expect($this->calendar->addBusinessDays($thursday, 1)->format('Y-m-d'))->toBe('2026-09-28');
});

it('contributes only the morning to a half-day holiday', function () {
    Holiday::create([
        'date' => '2026-09-24',
        'name' => 'Festival eve',
        'closes_at' => '13:00',
        'source' => 'manual',
    ]);

    $this->calendar->forgetHolidays();

    $thursday = CarbonImmutable::parse('2026-09-24 00:00:00', 'Asia/Kuala_Lumpur');
    $intervals = $this->calendar->intervalsFor($thursday);

    // One interval, ending at the closing time. Half a day is four hours, not eight
    // and not zero.
    expect($intervals)->toHaveCount(1)
        ->and($intervals[0][0]->format('H:i'))->toBe('09:00')
        ->and($intervals[0][1]->format('H:i'))->toBe('13:00');

    expect($this->calendar->workingHoursBetween(
        CarbonImmutable::parse('2026-09-24 00:00:00', 'Asia/Kuala_Lumpur'),
        CarbonImmutable::parse('2026-09-25 00:00:00', 'Asia/Kuala_Lumpur'),
    ))->toBe(4.0);
});

it('treats a full-day holiday as contributing no working time', function () {
    Holiday::create([
        'date' => '2026-09-24',
        'name' => 'National holiday',
        'closes_at' => null,
        'source' => 'manual',
    ]);

    $this->calendar->forgetHolidays();

    $thursday = CarbonImmutable::parse('2026-09-24 00:00:00', 'Asia/Kuala_Lumpur');

    expect($this->calendar->isWorkingDay($thursday))->toBeFalse()
        ->and($this->calendar->intervalsFor($thursday))->toBe([]);
});

it('lands three business days ahead for a 24 working hour target', function () {
    // The committee gets ten days and the owner three — this is the arithmetic
    // underneath those numbers.
    $monday = CarbonImmutable::parse('2026-09-21 09:00:00', 'Asia/Kuala_Lumpur');
    $due = $this->calendar->addWorkingHours($monday, 24);

    // Mon 8h, Tue 8h, Wed 8h = 24 working hours, so the target closes at the end of
    // Wednesday — at 18:00, the point at which eight working hours have elapsed
    // since 09:00 with the lunch hour excluded.
    expect($due->format('Y-m-d H:i'))->toBe('2026-09-23 18:00');
});

it('returns zero for a reversed range rather than a negative duration', function () {
    $later = CarbonImmutable::parse('2026-09-24 17:00:00', 'Asia/Kuala_Lumpur');
    $earlier = CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Kuala_Lumpur');

    expect($this->calendar->workingHoursBetween($later, $earlier))->toBe(0.0);
});
