<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CalendarInterval;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CalendarIntervalTest extends TestCase
{
    private Carbon $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = Carbon::parse('2024-01-15');
    }

    public function test_calculates_next_due_at_for_days(): void
    {
        $result = CalendarInterval::calculateNextDueAt($this->anchor, 'days', 30);

        $this->assertSame('2024-02-14', $result->toDateString());
    }

    public function test_calculates_next_due_at_for_weeks(): void
    {
        $result = CalendarInterval::calculateNextDueAt($this->anchor, 'weeks', 2);

        $this->assertSame('2024-01-29', $result->toDateString());
    }

    public function test_calculates_next_due_at_for_months(): void
    {
        $result = CalendarInterval::calculateNextDueAt($this->anchor, 'months', 6);

        $this->assertSame('2024-07-15', $result->toDateString());
    }

    public function test_calculates_next_due_at_for_years(): void
    {
        $result = CalendarInterval::calculateNextDueAt($this->anchor, 'years', 1);

        $this->assertSame('2025-01-15', $result->toDateString());
    }

    public function test_throws_on_unknown_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarInterval::calculateNextDueAt($this->anchor, 'hours', 1000);
    }

    public function test_does_not_mutate_anchor(): void
    {
        $original = $this->anchor->toDateString();

        CalendarInterval::calculateNextDueAt($this->anchor, 'months', 6);

        $this->assertSame($original, $this->anchor->toDateString());
    }
}
