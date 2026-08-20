<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\DsnStation;
use PHPUnit\Framework\TestCase;

final class DsnStationTest extends TestCase
{
    public function testResolvesGoldstoneDish(): void
    {
        $station = DsnStation::locate('DSS14');

        $this->assertSame('Goldstone', $station['complex']);
        $this->assertSame('Goldstone, California, USA', $station['location']);
        $this->assertSame('/assets/img/flags/us.svg', $station['flagSrc']);
    }

    public function testResolvesCanberraDish(): void
    {
        $station = DsnStation::locate('DSS43');

        $this->assertSame('Canberra', $station['complex']);
    }

    public function testResolvesMadridDish(): void
    {
        $station = DsnStation::locate('DSS63');

        $this->assertSame('Madrid', $station['complex']);
    }

    public function testReturnsNullForNullDishName(): void
    {
        $this->assertNull(DsnStation::locate(null));
    }

    public function testReturnsNullForUnrecognizedDishNumber(): void
    {
        $this->assertNull(DsnStation::locate('DSS99'));
    }

    public function testReturnsNullForUnparsableName(): void
    {
        $this->assertNull(DsnStation::locate('not-a-dish'));
    }
}
