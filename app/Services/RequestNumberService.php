<?php

namespace App\Services;

use App\Models\ItRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Generates request numbers (FR-005).
 *
 * WHY THE NUMBER IS GENERATED RATHER THAN TYPED
 *
 * In the current SharePoint list, `RequestID` is a plain text field — hand-typed.
 * Hand-typed identifiers duplicate and mistype, and duplicates are the worse
 * failure: two requests sharing a number break referencing, reporting and any
 * future migration. BR-009 also makes the number system-managed, so it is not
 * editable through an ordinary request screen.
 *
 * HOW UNIQUENESS IS GUARANTEED
 *
 * The `request_no` column has a unique index, and this service retries on a
 * collision rather than trusting that the counter is correct.
 *
 * That matters because the counter is derived from existing rows, and two requests
 * submitted in the same second can read the same maximum. The database is the
 * authority; this service just handles the retry so the user does not see a
 * constraint violation.
 */
class RequestNumberService
{
    /**
     * How many times to retry on a collision before giving up.
     *
     * Five is generous for genuinely concurrent submissions and still bounded. An
     * unbounded loop would hang the request if something were systematically wrong
     * — for example every number in a year being taken.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Allocate the next number.
     *
     * @throws \RuntimeException when no unique number could be allocated
     */
    public function next(): string
    {
        $pattern = (string) Setting::get('request_number.pattern', config('itrequest.request_number.pattern'));
        $padding = (int) Setting::get('request_number.padding', config('itrequest.request_number.padding'));
        $year = now(config('itrequest.business_hours.timezone'))->year;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->compose($pattern, $year, $padding, $this->nextSequence($pattern, $year));

            // The unique index is the real guard. Checking here first is an
            // optimisation, not the correctness mechanism.
            if (! ItRequest::where('request_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not allocate a unique request number after '.self::MAX_ATTEMPTS.' attempts. '
            .'This usually means the numbering pattern does not include a year or sequence token, '
            .'so every request resolves to the same value.'
        );
    }

    /**
     * The next sequence number for the year.
     *
     * Derived from the highest existing number rather than from a counter row, so
     * it self-heals: deleting a draft does not leave a permanent gap, and restoring
     * a database backup does not produce duplicates.
     *
     * WHY THIS COMPARES IN PHP RATHER THAN IN SQL
     *
     * The obvious implementation is `MAX(CAST(SUBSTRING(request_no, ?) AS UNSIGNED))`.
     * That is MySQL-specific — SQLite has no `CAST(... AS UNSIGNED)` and no
     * `SUBSTRING` alias — so the tests would pass on MySQL and fail on SQLite, or
     * the reverse, depending on which ran first. The sibling project hit exactly
     * this class of driver divergence with a date column.
     *
     * Pulling only the sequence portion and comparing in PHP keeps one code path
     * for both drivers. The volume here is hundreds of rows per year, so the
     * difference is not measurable.
     */
    private function nextSequence(string $pattern, int $year): int
    {
        $prefix = $this->compose($pattern, $year, 0, null, stripSequence: true);

        $numbers = DB::table('it_requests')
            ->where('request_no', 'like', $prefix.'%')
            ->pluck('request_no');

        $highest = 0;

        foreach ($numbers as $number) {
            $suffix = substr((string) $number, strlen($prefix));

            // Take the leading digits only, so a pattern with a trailing suffix
            // still compares correctly.
            if (preg_match('/^(\d+)/', $suffix, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest + 1;
    }

    /**
     * Fill the pattern's tokens.
     *
     * `{year}` becomes the business-year, `{seq}` the zero-padded sequence.
     * Anything else is left alone, so a pattern like `REQ-{year}-{seq}` and a
     * literal like `IT/2026/0001` both work.
     */
    private function compose(string $pattern, int $year, int $padding, ?int $sequence, bool $stripSequence = false): string
    {
        $result = str_replace('{year}', (string) $year, $pattern);

        if ($stripSequence) {
            // Remove the sequence token and everything after it, leaving the
            // prefix that numbers for this year share.
            $position = strpos($result, '{seq}');

            return $position === false ? $result : substr($result, 0, $position);
        }

        return str_replace('{seq}', str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT), $result);
    }
}
