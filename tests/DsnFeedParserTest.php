<?php

declare(strict_types=1);

namespace App\Tests;

use App\DataSource\DsnFeedParser;
use PHPUnit\Framework\TestCase;

final class DsnFeedParserTest extends TestCase
{
    public function testFindsActiveDownSignalForMatchingSpacecraft(): void
    {
        $xml = new \SimpleXMLElement(<<<'XML'
<dsn>
  <dish name="DSS43" azimuthAngle="90" elevationAngle="45">
    <downSignal active="true" signalType="data" dataRate="160" band="X" power="-160" spacecraft="VGR1" spacecraftID="-31"/>
    <target name="VGR1" id="31"/>
  </dish>
</dsn>
XML);

        $status = DsnFeedParser::findSignalStatus($xml, '-31');

        $this->assertTrue($status['inContact']);
        $this->assertSame('DSS43', $status['dishName']);
        $this->assertSame('down', $status['direction']);
        $this->assertSame('data', $status['signalType']);
        $this->assertSame(160.0, $status['dataRateBps']);
        $this->assertSame('X', $status['band']);
        $this->assertSame(-160.0, $status['power']);
    }

    public function testIgnoresInactiveSignals(): void
    {
        $xml = new \SimpleXMLElement(<<<'XML'
<dsn>
  <dish name="DSS43">
    <downSignal active="false" spacecraft="VGR1" spacecraftID="-31"/>
  </dish>
</dsn>
XML);

        $status = DsnFeedParser::findSignalStatus($xml, '-31');

        $this->assertFalse($status['inContact']);
        $this->assertNull($status['dishName']);
        $this->assertNull($status['direction']);
        $this->assertNull($status['signalType']);
        $this->assertNull($status['dataRateBps']);
        $this->assertNull($status['band']);
        $this->assertNull($status['power']);
    }

    public function testReturnsNotInContactWhenNoDishTargetsProbe(): void
    {
        $xml = new \SimpleXMLElement(<<<'XML'
<dsn>
  <dish name="DSS25">
    <downSignal active="true" spacecraft="MRO" spacecraftID="-74"/>
  </dish>
  <dish name="DSS14">
    <upSignal active="true" spacecraft="M01O" spacecraftID="-53"/>
  </dish>
</dsn>
XML);

        $status = DsnFeedParser::findSignalStatus($xml, '-31');

        $this->assertFalse($status['inContact']);
        $this->assertNull($status['dishName']);
    }

    public function testMatchesOnUpSignalToo(): void
    {
        $xml = new \SimpleXMLElement(<<<'XML'
<dsn>
  <dish name="DSS24">
    <upSignal active="true" spacecraft="VGR2" spacecraftID="-32"/>
  </dish>
</dsn>
XML);

        $status = DsnFeedParser::findSignalStatus($xml, '-32');

        $this->assertTrue($status['inContact']);
        $this->assertSame('DSS24', $status['dishName']);
        $this->assertSame('up', $status['direction']);
        $this->assertNull($status['signalType']);
        $this->assertNull($status['dataRateBps']);
    }
}
