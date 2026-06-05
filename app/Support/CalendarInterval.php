<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class CalendarInterval
{
    public static function calculateNextDueAt(Carbon $anchor, string $unit, int $value): Carbon
    {
        return match ($unit) {
            'days' => $anchor->copy()->addDays($value),
            'weeks' => $anchor->copy()->addWeeks($value),
            'months' => $anchor->copy()->addMonths($value),
            'years' => $anchor->copy()->addYears($value),
            default => throw new InvalidArgumentException("Unknown calendar unit: {$unit}"),
        };
    }
}
