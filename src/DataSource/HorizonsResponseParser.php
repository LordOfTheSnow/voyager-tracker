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
     * Every row of an Earth-centered series requested with QUANTITIES=20,21
     * (range, range-rate, one-way light time) -- unlike parseObserverRow,
     * which only looks at the first row for a single "as of now" reading,
     * this is for a multi-year ephemeris scan (see
     * HorizonsClient::fetchEarthLightTimeSeries) where every sampled point
     * matters.
     *
     * @return list<array{date: string, delta: float, deldot: float, lightTimeMinutes: float}>
     */
    public static function parseObserverSeries(string $result): array
    {
        return array_map(
            static fn (array $columns) => [
                'date' => "{$columns[0]} {$columns[1]}",
                'delta' => (float) $columns[2],
                'deldot' => (float) $columns[3],
                'lightTimeMinutes' => (float) $columns[4],
            ],
            self::allDataRowColumns($result, minColumns: 5),
        );
    }

    /**
     * Sun-centered row requested with QUANTITIES=20,31: range, range-rate,
     * and the target's heliocentric ecliptic longitude/latitude as seen from
     * the Sun -- longitude places a probe's heading on the top-down orrery,
     * latitude is the real angle its trajectory makes with the ecliptic
     * plane (both Voyagers are now moving essentially radially outward, so
     * this angle barely changes year to year).
     *
     * @return array{delta: float, deldot: float, eclipticLongitudeDeg: float, eclipticLatitudeDeg: float}
     */
    public static function parseHeliocentricRow(string $result): array
    {
        $columns = self::firstDataRowColumns($result, minColumns: 6);

        return [
            'delta' => (float) $columns[2],
            'deldot' => (float) $columns[3],
            'eclipticLongitudeDeg' => (float) $columns[4],
            'eclipticLatitudeDeg' => (float) $columns[5],
        ];
    }

    /** @return list<string> */
    private static function firstDataRowColumns(string $result, int $minColumns): array
    {
        return self::allDataRowColumns($result, $minColumns)[0];
    }

    /** @return list<list<string>> */
    private static function allDataRowColumns(string $result, int $minColumns): array
    {
        if (!preg_match('/\$\$SOE(.*?)\$\$EOE/s', $result, $block)) {
            throw new \RuntimeException('Horizons response did not contain an ephemeris data block.');
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block[1])))));
        if ($lines === []) {
            throw new \RuntimeException('Horizons response ephemeris block was empty.');
        }

        // Columns: "YYYY-Mon-DD HH:MM   <requested quantities...>"
        return array_map(static function (string $line) use ($minColumns) {
            $columns = preg_split('/\s+/', $line);
            if (count($columns) < $minColumns) {
                throw new \RuntimeException('Unexpected Horizons ephemeris row shape: ' . $line);
            }

            return $columns;
        }, $lines);
    }
}
