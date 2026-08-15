<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Orrery;
use PHPUnit\Framework\TestCase;

final class OrreryTest extends TestCase
{
    public function testProjectsCardinalLongitudes(): void
    {
        $this->assertProjectsTo(0.0, 110.0, 30.0);
        $this->assertProjectsTo(90.0, 10.0, 30.0 - 100.0);
        $this->assertProjectsTo(180.0, -90.0, 30.0);
        $this->assertProjectsTo(270.0, 10.0, 30.0 + 100.0);
    }

    private function assertProjectsTo(float $longitudeDeg, float $expectedX, float $expectedY): void
    {
        $point = Orrery::project($longitudeDeg, radiusPx: 100.0, centerX: 10.0, centerY: 30.0);

        $this->assertEqualsWithDelta($expectedX, $point['x'], 1e-9);
        $this->assertEqualsWithDelta($expectedY, $point['y'], 1e-9);
    }

    public function testNormalizeRadiusInterpolatesLinearly(): void
    {
        $this->assertEqualsWithDelta(200.0, Orrery::normalizeRadius(140.0, 140.0, 170.0, 200.0, 260.0), 1e-9);
        $this->assertEqualsWithDelta(260.0, Orrery::normalizeRadius(170.0, 140.0, 170.0, 200.0, 260.0), 1e-9);
        $this->assertEqualsWithDelta(230.0, Orrery::normalizeRadius(155.0, 140.0, 170.0, 200.0, 260.0), 1e-9);
    }

    public function testNormalizeRadiusFallsBackToMidpointWhenValuesAreEqual(): void
    {
        $this->assertEqualsWithDelta(230.0, Orrery::normalizeRadius(150.0, 150.0, 150.0, 200.0, 260.0), 1e-9);
    }
}
