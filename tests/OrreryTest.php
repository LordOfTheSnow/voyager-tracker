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

    public function testLogRadiusIsZeroAtTheOffset(): void
    {
        $this->assertEqualsWithDelta(0.0, Orrery::logRadius(0.0, 170.0), 1e-9);
    }

    public function testLogRadiusGrowsSlowerThanDistance(): void
    {
        $earthRadius = Orrery::logRadius(1.0, 170.0);
        $plutoRadius = Orrery::logRadius(35.6, 170.0);
        $voyager1Radius = Orrery::logRadius(171.4, 170.0);

        // Pluto is ~35x farther than Earth, but its plotted radius is nowhere near 35x Earth's.
        $this->assertGreaterThan($earthRadius, $plutoRadius);
        $this->assertLessThan($earthRadius * 35, $plutoRadius);

        // Still correctly ordered even out at interstellar distances.
        $this->assertGreaterThan($plutoRadius, $voyager1Radius);
    }

    public function testLogRadiusNeverGoesNegativeForOutOfRangeInput(): void
    {
        $this->assertEqualsWithDelta(0.0, Orrery::logRadius(-5.0, 170.0), 1e-9);
    }

    public function testHomeLayoutSizesCanvasAroundWhicheverProbeIsFartherOut(): void
    {
        // v1 farther than v2, so pxPerAu is set by v1 (255px / 100 AU = 2.55 px/AU).
        // Cardinal longitudes keep the trig clean: v1 due right, v2 due left,
        // Neptune due "up" (negative y, SVG convention), New Horizons due "down".
        $layout = Orrery::homeLayout(
            v1: ['distanceAu' => 100.0, 'longitudeDeg' => 0.0],
            v2: ['distanceAu' => 80.0, 'longitudeDeg' => 180.0],
            neptune: ['distanceAu' => 30.0, 'longitudeDeg' => 90.0],
            newHorizons: ['distanceAu' => 50.0, 'longitudeDeg' => 270.0],
        );

        // Canvas reach on the right is driven by v1's label footprint (255 + 58 = 313),
        // not by the heliopause ring (306) -- confirms label clearance is accounted for.
        $this->assertEqualsWithDelta(667.0, $layout['canvasWidth'], 1e-6);
        $this->assertEqualsWithDelta(660.0, $layout['canvasHeight'], 1e-6);
        $this->assertEqualsWithDelta(306.0, $layout['heliopauseRadiusPx'], 1e-6);
        $this->assertEqualsWithDelta(76.5, $layout['neptuneRadiusPx'], 1e-6);

        $this->assertEqualsWithDelta(330.0, $layout['sun']['x'], 1e-6);
        $this->assertEqualsWithDelta(330.0, $layout['sun']['y'], 1e-6);

        $this->assertEqualsWithDelta(585.0, $layout['v1']['x'], 1e-6);
        $this->assertEqualsWithDelta(330.0, $layout['v1']['y'], 1e-6);
        $this->assertSame('100.0 AU', $layout['v1']['distanceLabel']);

        $this->assertEqualsWithDelta(126.0, $layout['v2']['x'], 1e-6);
        $this->assertEqualsWithDelta(330.0, $layout['v2']['y'], 1e-6);

        $this->assertEqualsWithDelta(330.0, $layout['neptune']['x'], 1e-6);
        $this->assertEqualsWithDelta(253.5, $layout['neptune']['y'], 1e-6);

        $this->assertEqualsWithDelta(330.0, $layout['newHorizons']['x'], 1e-6);
        $this->assertEqualsWithDelta(457.5, $layout['newHorizons']['y'], 1e-6);
    }

    public function testDistanceModalLayoutPreservesBodyOrderAndBuildsBothScales(): void
    {
        // Distances chosen so log10(1 + distanceAu) lands on a whole number,
        // keeping the log-scale radii exact rather than needing a re-derived delta.
        $layout = Orrery::distanceModalLayout([
            ['slug' => 'earth', 'label' => 'Earth', 'distanceAu' => 9.0, 'longitudeDeg' => 0.0],
            ['slug' => 'v1', 'label' => 'V1', 'distanceAu' => 99.0, 'longitudeDeg' => 90.0],
        ]);

        // Sun first, then bodies in the order they were passed in -- the template
        // iterates this array directly, so insertion order is part of the contract.
        $this->assertSame(['sun', 'earth', 'v1'], array_keys($layout['log']));
        $this->assertSame(['sun', 'earth', 'v1'], array_keys($layout['linear']));

        $this->assertSame(['x' => 450.0, 'y' => 450.0, 'label' => 'Sun'], $layout['log']['sun']);

        $this->assertEqualsWithDelta(170.0, $layout['log']['earth']['radiusPx'], 1e-9);
        $this->assertEqualsWithDelta(620.0, $layout['log']['earth']['x'], 1e-6);
        $this->assertEqualsWithDelta(450.0, $layout['log']['earth']['y'], 1e-6);
        $this->assertSame('9.0 AU', $layout['log']['earth']['distanceLabel']);

        $this->assertEqualsWithDelta(40.5, $layout['linear']['earth']['radiusPx'], 1e-9);
        $this->assertEqualsWithDelta(490.5, $layout['linear']['earth']['x'], 1e-6);

        $this->assertEqualsWithDelta(340.0, $layout['log']['v1']['radiusPx'], 1e-9);
        $this->assertEqualsWithDelta(110.0, $layout['log']['v1']['y'], 1e-6);

        $this->assertEqualsWithDelta(445.5, $layout['linear']['v1']['radiusPx'], 1e-9);
        $this->assertEqualsWithDelta(4.5, $layout['linear']['v1']['y'], 1e-6);

        $this->assertSame('Heliopause (approx.)', $layout['heliopause']['label']);
        $this->assertSame('120.0 AU', $layout['heliopause']['distanceLabel']);
        $this->assertEqualsWithDelta(170.0 * log10(121.0), $layout['heliopause']['logRadiusPx'], 1e-9);
        $this->assertEqualsWithDelta(540.0, $layout['heliopause']['linearRadiusPx'], 1e-9);
    }

    public function testEclipticDiagramAboveTheEclipticPlane(): void
    {
        $diagram = Orrery::eclipticDiagram(latitudeDeg: 30.0, inContact: true);

        $this->assertEqualsWithDelta(184.904, $diagram['probe']['x'], 1e-3);
        $this->assertEqualsWithDelta(55.0, $diagram['probe']['y'], 1e-3);
        $this->assertEqualsWithDelta(40.0, $diagram['probeLabelY'], 1e-3);
        $this->assertSame(0, $diagram['arcSweepFlag']);
        $this->assertSame('above', $diagram['directionWord']);
        $this->assertSame("30.0\u{b0}", $diagram['angleLabel']);
        $this->assertTrue($diagram['inContact']);
    }

    public function testEclipticDiagramBelowTheEclipticPlane(): void
    {
        $diagram = Orrery::eclipticDiagram(latitudeDeg: -45.0, inContact: false);

        $this->assertEqualsWithDelta(161.066, $diagram['probe']['x'], 1e-3);
        $this->assertEqualsWithDelta(236.066, $diagram['probe']['y'], 1e-3);
        // Below the plane, the label drops under the probe dot instead of sitting above it.
        $this->assertEqualsWithDelta(260.066, $diagram['probeLabelY'], 1e-3);
        $this->assertSame(1, $diagram['arcSweepFlag']);
        $this->assertSame('below', $diagram['directionWord']);
        $this->assertSame("45.0\u{b0}", $diagram['angleLabel']);
        $this->assertFalse($diagram['inContact']);
    }
}
