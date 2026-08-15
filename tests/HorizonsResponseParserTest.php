<?php

declare(strict_types=1);

namespace App\Tests;

use App\DataSource\HorizonsResponseParser;
use PHPUnit\Framework\TestCase;

final class HorizonsResponseParserTest extends TestCase
{
    private const SAMPLE_RESULT = <<<'TEXT'
*******************************************************************************
Ephemeris / API_USER Sat Aug 15 06:21:38 2026 Pasadena, USA      / Horizons
*******************************************************************************
Target body name: Voyager 1 (spacecraft) (-31)    {source: Voyager_1_ST+refit2022_m}
 Date__(UT)__HR:MN                delta      deldot  1-way_down_LT
******************************************************************
$$SOE
 2026-Aug-15 00:00     171.449196106214  16.8819410  1425.89948403
 2026-Aug-16 00:00     171.458945692629  16.8819357  1425.98056887
$$EOE
*******************************************************************************
TEXT;

    public function testParsesFirstDataRow(): void
    {
        $row = HorizonsResponseParser::parseObserverRow(self::SAMPLE_RESULT);

        $this->assertEqualsWithDelta(171.449196106214, $row['delta'], 1e-9);
        $this->assertEqualsWithDelta(16.8819410, $row['deldot'], 1e-9);
        $this->assertEqualsWithDelta(1425.89948403, $row['lightTimeMinutes'], 1e-9);
    }

    public function testThrowsWhenNoDataBlockPresent(): void
    {
        $this->expectException(\RuntimeException::class);

        HorizonsResponseParser::parseObserverRow('no ephemeris here');
    }

    public function testThrowsWhenDataBlockIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);

        HorizonsResponseParser::parseObserverRow("stuff\n\$\$SOE\n\$\$EOE\nmore stuff");
    }

    public function testThrowsWhenRowHasUnexpectedShape(): void
    {
        $this->expectException(\RuntimeException::class);

        HorizonsResponseParser::parseObserverRow("\$\$SOE\n only two columns\n\$\$EOE");
    }

    private const SUN_CENTERED_SAMPLE_RESULT = <<<'TEXT'
*******************************************************************************
Target body name: Voyager 1 (spacecraft) (-31)    {source: Voyager_1_ST+refit2022_m}
 Date__(UT)__HR:MN                delta      deldot     ObsEcLon    ObsEcLat
****************************************************************************
$$SOE
 2026-Aug-15 00:00     171.449196106214  16.8819410  256.7593255  35.1566063
 2026-Aug-16 00:00     171.458945692629  16.8819357  256.7595857  35.1566326
$$EOE
TEXT;

    public function testParsesHeliocentricRow(): void
    {
        $row = HorizonsResponseParser::parseHeliocentricRow(self::SUN_CENTERED_SAMPLE_RESULT);

        $this->assertEqualsWithDelta(171.449196106214, $row['delta'], 1e-9);
        $this->assertEqualsWithDelta(16.8819410, $row['deldot'], 1e-9);
        $this->assertEqualsWithDelta(256.7593255, $row['eclipticLongitudeDeg'], 1e-9);
    }

    private const ECLIPTIC_LONGITUDE_ONLY_SAMPLE_RESULT = <<<'TEXT'
*******************************************************************************
Target body name: Earth (399)
 Date__(UT)__HR:MN        ObsEcLon    ObsEcLat
**********************************************
$$SOE
 2026-Aug-15 00:00     321.8352740   0.0019180
 2026-Aug-16 00:00     322.7963698   0.0019031
$$EOE
TEXT;

    public function testParsesEclipticLongitude(): void
    {
        $longitude = HorizonsResponseParser::parseEclipticLongitude(self::ECLIPTIC_LONGITUDE_ONLY_SAMPLE_RESULT);

        $this->assertEqualsWithDelta(321.8352740, $longitude, 1e-9);
    }
}
