<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use Illuminate\Support\Carbon;

/**
 * Counting in working days, which is what the SLA is stated in.
 *
 * Weekends plus Nigerian public holidays. The holidays are configuration
 * because half of them move: the Islamic dates follow the lunar calendar and
 * are announced a few days ahead, so they cannot be computed and must be
 * maintained.
 *
 * The answer is computed once, at payment, and stored on the order. A promise a
 * customer was given must not drift because somebody corrected the holiday
 * calendar in November.
 */
final class WorkingDays
{
    /** The date a promise of N working days lands on, counting from tomorrow. */
    public function after(Carbon $from, int $workingDays): Carbon
    {
        $holidays = $this->holidays();
        $date = $from->copy()->startOfDay();
        $counted = 0;

        // Counted from the next day, not from today. A customer paying at four
        // in the afternoon has not had a working day out of us.
        while ($counted < $workingDays) {
            $date->addDay();

            if ($this->isWorkingDay($date, $holidays)) {
                $counted++;
            }
        }

        return $date;
    }

    /**
     * Whether a given day counts.
     *
     * @param  list<string>|null  $holidays  ISO dates, read from config when omitted.
     */
    public function isWorkingDay(Carbon $date, ?array $holidays = null): bool
    {
        $holidays ??= $this->holidays();

        if ($date->isWeekend()) {
            return false;
        }

        return ! in_array($date->toDateString(), $holidays, true);
    }

    /**
     * How many working days lie between two dates, exclusive of the first.
     *
     * Used to report a breach rather than to make a promise, which is why it
     * tolerates the second date being earlier and answers negatively.
     */
    public function between(Carbon $from, Carbon $to): int
    {
        $holidays = $this->holidays();
        $sign = $to->lessThan($from) ? -1 : 1;
        $cursor = $from->copy()->startOfDay();
        $target = $to->copy()->startOfDay();
        $count = 0;

        while (! $cursor->equalTo($target)) {
            $cursor->addDays($sign);

            if ($this->isWorkingDay($cursor, $holidays)) {
                $count++;
            }
        }

        return $count * $sign;
    }

    /** @return list<string> */
    private function holidays(): array
    {
        /** @var list<string> $configured */
        $configured = config('geoverify.public_holidays', []);

        return $configured;
    }
}
