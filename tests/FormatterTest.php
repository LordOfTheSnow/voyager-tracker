<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Formatter;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    public function testYearsMonthsDaysSinceFormatsCalendarAwareDuration(): void
    {
        $isoDate = (new \DateTimeImmutable('-48 years -6 months -3 days'))->format('Y-m-d');

        $this->assertSame('48 yrs, 6 m, 3 days', Formatter::yearsMonthsDaysSince($isoDate));
    }

    public function testYearsMonthsDaysSinceUsesSingularUnitsWhenExactlyOne(): void
    {
        $isoDate = (new \DateTimeImmutable('-1 years -1 months -1 days'))->format('Y-m-d');

        $this->assertSame('1 yr, 1 m, 1 day', Formatter::yearsMonthsDaysSince($isoDate));
    }

    public function testOneWayLightTimeDoesNotRoundUpToAFullLightDay(): void
    {
        // Voyager 1 on 2026-11-17: ~10 s short of a full light-day.
        $this->assertSame('23h 59m', Formatter::oneWayLightTime(1439.835));
    }

    public function testOneWayLightTimeShowsFullLightDayOnceCrossed(): void
    {
        $this->assertSame('24h 0m', Formatter::oneWayLightTime(1440.0));
    }
}
