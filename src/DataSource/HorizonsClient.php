<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Thin client for JPL Horizons' OBSERVER ephemeris endpoint. Two separate
 * calls per probe are needed on every page load because Horizons only
 * accepts one CENTER per request: Sun-centered for distance/speed/heading,
 * Earth-centered for distance and one-way light time. A third, much
 * heavier call (fetchEarthLightTimeSeries) is made rarely -- cached for a
 * day at a time -- to project the next light-day crossing. "Speed" is the
 * Sun-centered range-rate
 * (deldot) -- the radial component of velocity relative to the Sun -- rather
 * than a true 3D velocity magnitude, which Horizons' OBSERVER mode doesn't
 * expose directly. For both Voyagers, now moving essentially straight
 * outward from the solar system, that radial component is a very close
 * approximation of total heliocentric speed.
 *
 * "Heading" (eclipticLongitudeDeg) is the target's heliocentric ecliptic
 * longitude -- QUANTITIES=31, "as seen from the Sun" -- i.e. its compass
 * direction on a flat top-down view of the solar system; the top-down
 * orrery still deliberately flattens ecliptic latitude (how far above/below
 * the plane the target actually is: ~35 deg for Voyager 1, ~38 deg for
 * Voyager 2), same spirit as the speed approximation above. That latitude
 * is still fetched (eclipticLatitudeDeg) for the per-probe detail page's
 * edge-on "angle against the ecliptic" diagram, which is precisely the one
 * place the flattened dimension matters.
 */
final class HorizonsClient
{
    private const AU_IN_KM = 149_597_870.7;

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * Also used for Earth and the outer planet barycenters (5=Jupiter,
     * 6=Saturn, 7=Uranus, 8=Neptune, 9=Pluto, 399=Earth) to place them on
     * the orrery -- "speed" is meaningless for those and simply unused.
     *
     * @return array{distanceFromSunKm: float, speedKmS: float, eclipticLongitudeDeg: float, eclipticLatitudeDeg: float}
     */
    public function fetchSunCentered(string $spkId): array
    {
        $result = $this->fetchEphemeris($spkId, center: '500@10', quantities: '20,31');
        $row = HorizonsResponseParser::parseHeliocentricRow($result);

        return [
            'distanceFromSunKm' => $row['delta'] * self::AU_IN_KM,
            'speedKmS' => abs($row['deldot']),
            'eclipticLongitudeDeg' => $row['eclipticLongitudeDeg'],
            'eclipticLatitudeDeg' => $row['eclipticLatitudeDeg'],
        ];
    }

    /** @return array{distanceFromEarthKm: float, lightTimeMinutes: float} */
    public function fetchEarthCentered(string $spkId): array
    {
        $result = $this->fetchEphemeris($spkId, center: '500@399', quantities: '20,21');
        $row = HorizonsResponseParser::parseObserverRow($result);

        return [
            'distanceFromEarthKm' => $row['delta'] * self::AU_IN_KM,
            'lightTimeMinutes' => $row['lightTimeMinutes'],
        ];
    }

    /**
     * A coarse future scan of Earth-centered one-way light time, used to
     * find when it will next cross a whole light-day boundary. This can't
     * be extrapolated from a single "as of now" speed reading the way the
     * rest of this app approximates things: Earth's own ~29.8 km/s orbital
     * motion makes a probe's *observed* recession rate (as seen from Earth,
     * not the Sun) swing between roughly -8 and +41 km/s over a year --
     * fast enough that a naive constant-rate projection can land months off
     * for a probe already close to a crossing. Real future ephemeris
     * samples are the only way to capture that. 10-day steps keep the
     * request to a few hundred rows even over a decade-plus span while
     * still resolving that annual wobble.
     *
     * @return list<array{date: int, lightTimeMinutes: float}> date is a Unix timestamp
     */
    public function fetchEarthLightTimeSeries(string $spkId, int $spanDays, string $stepSize = '10d'): array
    {
        $start = gmdate('Y-m-d H:i');
        $stop = gmdate('Y-m-d H:i', time() + $spanDays * 86400);

        $result = $this->fetchEphemeris($spkId, center: '500@399', quantities: '20,21', start: $start, stop: $stop, stepSize: $stepSize);

        return array_map(
            static fn (array $row) => [
                'date' => \DateTimeImmutable::createFromFormat('Y-M-d H:i', $row['date'], new \DateTimeZone('UTC'))->getTimestamp(),
                'lightTimeMinutes' => $row['lightTimeMinutes'],
            ],
            HorizonsResponseParser::parseObserverSeries($result),
        );
    }

    private function fetchEphemeris(string $spkId, string $center, string $quantities, ?string $start = null, ?string $stop = null, string $stepSize = '5m'): string
    {
        $start ??= gmdate('Y-m-d H:i');
        $stop ??= gmdate('Y-m-d H:i', time() + 300);

        $query = http_build_query([
            'format' => 'json',
            'COMMAND' => "'{$spkId}'",
            'OBJ_DATA' => 'NO',
            'MAKE_EPHEM' => 'YES',
            'EPHEM_TYPE' => 'OBSERVER',
            'CENTER' => "'{$center}'",
            'QUANTITIES' => "'{$quantities}'",
            'START_TIME' => "'{$start}'",
            'STOP_TIME' => "'{$stop}'",
            'STEP_SIZE' => "'{$stepSize}'",
        ]);

        $raw = $this->httpGet("{$this->apiUrl}?{$query}");
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (!isset($payload['result'])) {
            throw new \RuntimeException('Horizons response missing "result" field.');
        }

        return $payload['result'];
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'voyager-tracker/1.0',
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("Horizons request failed: {$error}");
        }
        if ($status !== 200) {
            throw new \RuntimeException("Horizons request returned HTTP {$status}");
        }

        return $body;
    }
}
