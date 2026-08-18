<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\LightDayProjection;
use PHPUnit\Framework\TestCase;

final class LightDayProjectionTest extends TestCase
{
    private const DAY_SECONDS = 86400;

    public function testInterpolatesBetweenTheStraddlingSamples(): void
    {
        $t0 = 1_700_000_000;
        $series = [
            ['date' => $t0, 'lightTimeMinutes' => 24 * 60 - 20],
            ['date' => $t0 + self::DAY_SECONDS, 'lightTimeMinutes' => 24 * 60 + 20],
        ];

        $result = LightDayProjection::findCrossing($series, currentLightTimeMinutes: 24 * 60 - 20);

        $this->assertSame(1, $result['targetLightDays']);
        // Target sits exactly halfway between the two samples' light times.
        $this->assertEqualsWithDelta($t0 + self::DAY_SECONDS / 2, $result['date']->getTimestamp(), 1);
    }

    public function testFindsTheFirstCrossingEvenWithANonMonotonicWobble(): void
    {
        $t0 = 1_700_000_000;
        // Mimics Earth's orbital wobble: light time dips back down after an
        // early spike before properly settling above the target later.
        $series = [
            ['date' => $t0, 'lightTimeMinutes' => 24 * 60 - 5],
            ['date' => $t0 + self::DAY_SECONDS, 'lightTimeMinutes' => 24 * 60 + 3],   // first crossing
            ['date' => $t0 + 2 * self::DAY_SECONDS, 'lightTimeMinutes' => 24 * 60 - 2], // dips back below
            ['date' => $t0 + 3 * self::DAY_SECONDS, 'lightTimeMinutes' => 24 * 60 + 10],
        ];

        $result = LightDayProjection::findCrossing($series, currentLightTimeMinutes: 24 * 60 - 5);

        $this->assertSame(1, $result['targetLightDays']);
        $this->assertGreaterThanOrEqual($t0, $result['date']->getTimestamp());
        $this->assertLessThan($t0 + self::DAY_SECONDS, $result['date']->getTimestamp());
    }

    public function testTargetsTheSecondLightDayOnceTheFirstIsAlreadyPassed(): void
    {
        $t0 = 1_700_000_000;
        $series = [
            ['date' => $t0, 'lightTimeMinutes' => 24 * 60 + 30],
            ['date' => $t0 + self::DAY_SECONDS, 'lightTimeMinutes' => 2 * 24 * 60 + 5],
        ];

        $result = LightDayProjection::findCrossing($series, currentLightTimeMinutes: 24 * 60 + 30);

        $this->assertSame(2, $result['targetLightDays']);
    }

    public function testReturnsNullWhenTheCrossingIsBeyondTheSeries(): void
    {
        $t0 = 1_700_000_000;
        $series = [
            ['date' => $t0, 'lightTimeMinutes' => 100.0],
            ['date' => $t0 + self::DAY_SECONDS, 'lightTimeMinutes' => 101.0],
        ];

        $this->assertNull(LightDayProjection::findCrossing($series, currentLightTimeMinutes: 100.0));
    }
}
