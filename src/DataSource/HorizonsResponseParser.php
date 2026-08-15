<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Pure parsing of a Horizons OBSERVER-ephemeris text block, split out from
 * HorizonsClient so it can be unit tested against fixture text without
 * hitting the network -- this is the part most likely to silently break if
 * JPL ever changes the response shape.
 */
final class HorizonsResponseParser
{
    /** @return array{delta: float, deldot: float, lightTimeMinutes: float} */
    public static function parseObserverRow(string $result): array
    {
        $columns = self::firstDataRowColumns($result, minColumns: 5);

        return [
            'delta' => (float) $columns[2],
            'deldot' => (float) $columns[3],
            'lightTimeMinutes' => (float) $columns[4],
        ];
    }

    /**
     * Sun-centered row requested with QUANTITIES=20,31: range, range-rate,
     * and the target's heliocentric ecliptic longitude/latitude as seen from
     * the Sun -- used to place a probe's heading on the orrery.
     *
     * @return array{delta: float, deldot: float, eclipticLongitudeDeg: float}
     */
    public static function parseHeliocentricRow(string $result): array
    {
        $columns = self::firstDataRowColumns($result, minColumns: 5);

        return [
            'delta' => (float) $columns[2],
            'deldot' => (float) $columns[3],
            'eclipticLongitudeDeg' => (float) $columns[4],
        ];
    }

    /** Row requested with QUANTITIES=31 only: just ecliptic longitude/latitude. */
    public static function parseEclipticLongitude(string $result): float
    {
        $columns = self::firstDataRowColumns($result, minColumns: 3);

        return (float) $columns[2];
    }

    /** @return list<string> */
    private static function firstDataRowColumns(string $result, int $minColumns): array
    {
        if (!preg_match('/\$\$SOE(.*?)\$\$EOE/s', $result, $block)) {
            throw new \RuntimeException('Horizons response did not contain an ephemeris data block.');
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block[1])))));
        if ($lines === []) {
            throw new \RuntimeException('Horizons response ephemeris block was empty.');
        }

        // Columns: "YYYY-Mon-DD HH:MM   <requested quantities...>"
        $columns = preg_split('/\s+/', $lines[0]);
        if (count($columns) < $minColumns) {
            throw new \RuntimeException('Unexpected Horizons ephemeris row shape: ' . $lines[0]);
        }

        return $columns;
    }
}
