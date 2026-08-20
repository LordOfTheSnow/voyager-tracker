<?php

declare(strict_types=1);

namespace App\Support;

final class Formatter
{
    // Reference points for the DSN link card's "in human terms" comparisons.
    // All illustrative, same spirit as the app's other labeled approximations
    // (heliopause ring, instrument health): real fixed benchmarks, not live
    // data, chosen because they're widely recognizable.
    private const DIALUP_MODEM_BPS = 56_000.0;
    private const WIFI_REFERENCE_DBM = -50.0; // a solid few-bars home WiFi signal
    private const MICROWAVE_OVEN_KW = 1.1;

    // NASA Deep Space Network's actual allocated frequency windows per band (not the much
    // wider generic IEEE radar-band definitions) -- see the DSN Telecommunications Link
    // Design Handbook. Keyed by DsnFeedParser's raw band code and fetchSignalStatus's raw
    // 'up'/'down' direction. Ka-band is included for completeness even though Voyager itself
    // only ever links on S- and X-band.
    private const DEEP_SPACE_BAND_FREQUENCIES_MHZ = [
        'S' => ['down' => [2290, 2300], 'up' => [2110, 2120]],
        'X' => ['down' => [8400, 8450], 'up' => [7145, 7190]],
        'Ka' => ['down' => [31800, 32300], 'up' => [34200, 34700]],
    ];

    public static function distanceKm(float $km): string
    {
        return number_format($km / 1_000_000_000, 1) . 'B km';
    }

    public static function distanceKmPrecise(float $km): string
    {
        return number_format($km, 0) . ' km';
    }

    public static function distanceAu(float $au): string
    {
        return number_format($au, 1) . ' AU';
    }

    public static function distanceMiPrecise(float $km): string
    {
        return number_format($km / 1.609344, 0) . ' mi';
    }

    public static function speedKmS(float $kmPerSecond): string
    {
        return number_format($kmPerSecond, 1) . ' km/s';
    }

    public static function speedKmH(float $kmPerSecond): string
    {
        return number_format($kmPerSecond * 3600, 0) . ' km/h';
    }

    public static function speedMph(float $kmPerSecond): string
    {
        return number_format($kmPerSecond * 3600 / 1.609344, 0) . ' mph';
    }

    public static function oneWayLightTime(float $minutes): string
    {
        return self::hoursAndMinutes($minutes);
    }

    public static function roundTripLightTime(float $oneWayMinutes): string
    {
        $hours = (int) round(($oneWayMinutes * 2) / 60);

        return "~{$hours}h";
    }

    private static function hoursAndMinutes(float $totalMinutes): string
    {
        $totalMinutes = (int) round($totalMinutes);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return "{$hours}h {$minutes}m";
    }

    public static function daysSince(string $isoDate): int
    {
        $launch = new \DateTimeImmutable($isoDate, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $launch->diff($now)->days;
    }

    public static function daysSinceFormatted(string $isoDate): string
    {
        return number_format(self::daysSince($isoDate));
    }

    public static function dataRate(float $bps): string
    {
        if ($bps >= 1_000_000) {
            return number_format($bps / 1_000_000, 2) . ' Mbps';
        }
        if ($bps >= 1_000) {
            return number_format($bps / 1_000, 1) . ' kbps';
        }

        return number_format($bps, 0) . ' bps';
    }

    /** @param 'up'|'down'|null $direction */
    public static function bandFrequency(string $band, ?string $direction): ?string
    {
        $range = self::DEEP_SPACE_BAND_FREQUENCIES_MHZ[$band][$direction] ?? null;
        if ($range === null) {
            return null;
        }

        return number_format($range[0]) . '–' . number_format($range[1]) . ' MHz';
    }

    /** @param 'up'|'down' $direction */
    public static function signalPower(float $value, string $direction): string
    {
        return $direction === 'up'
            ? number_format($value, 1) . ' kW'
            : number_format($value, 0) . ' dBm';
    }

    public static function dataRateContext(float $bps): string
    {
        if ($bps <= 0.0) {
            return "carrier only \u{2014} no data currently modulated";
        }
        if ($bps < self::DIALUP_MODEM_BPS) {
            return self::ratioMagnitude(self::DIALUP_MODEM_BPS / $bps) . ' times slower than a 56k dial-up modem';
        }

        return self::ratioMagnitude($bps / self::DIALUP_MODEM_BPS) . ' times faster than a 56k dial-up modem';
    }

    /** @param 'up'|'down' $direction */
    public static function signalPowerContext(float $value, string $direction): string
    {
        if ($direction === 'up') {
            return $value >= self::MICROWAVE_OVEN_KW
                ? self::ratioMagnitude($value / self::MICROWAVE_OVEN_KW) . ' times the draw of a microwave oven'
                : 'less power than a microwave oven';
        }

        $decibelsBelowWifi = self::WIFI_REFERENCE_DBM - $value;
        if ($decibelsBelowWifi <= 0.0) {
            return 'as strong as a typical WiFi signal';
        }

        return self::ratioMagnitude(10 ** ($decibelsBelowWifi / 10)) . ' times fainter than a typical WiFi signal (-50 dBm)';
    }

    /** Renders a ratio like 100_000_000_000.0 as "100 billion" rather than a wall of digits. */
    private static function ratioMagnitude(float $ratio): string
    {
        $units = [
            [1_000_000_000_000.0, 'trillion'],
            [1_000_000_000.0, 'billion'],
            [1_000_000.0, 'million'],
            [1_000.0, 'thousand'],
        ];

        foreach ($units as [$threshold, $label]) {
            if ($ratio >= $threshold) {
                $scaled = $ratio / $threshold;

                return number_format($scaled, $scaled >= 100 ? 0 : 1) . ' ' . $label;
            }
        }

        return number_format($ratio, $ratio >= 10 ? 0 : 1);
    }

    public static function dateTimeUtc(\DateTimeImmutable $date): string
    {
        return $date->format('M j, Y, H:i') . ' UTC';
    }

    public static function dateOnly(\DateTimeImmutable $date): string
    {
        return $date->format('M j, Y');
    }

    public static function relativeTimeAgo(int $timestamp): string
    {
        $diffSeconds = max(0, time() - $timestamp);

        if ($diffSeconds < 60) {
            return 'just now';
        }

        $minutes = (int) floor($diffSeconds / 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute ago' : "{$minutes} minutes ago";
        }

        $hours = (int) floor($minutes / 60);

        return $hours === 1 ? '1 hour ago' : "{$hours} hours ago";
    }
}
