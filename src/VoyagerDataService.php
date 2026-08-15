<?php

declare(strict_types=1);

namespace App;

use App\Cache\FileCache;
use App\DataSource\DsnClient;
use App\DataSource\HorizonsClient;
use App\Support\Formatter;
use App\Support\Orrery;

final class VoyagerDataService
{
    private const AU_IN_KM = 149_597_870.7;

    // Home dashboard orrery geometry (pixel coords within each SVG's viewBox).
    // Probe radii are normalized between V1 and V2's own live distances
    // rather than to a fixed AU scale, so the layout keeps working as both
    // probes recede over time without needing recalibration.
    private const DEFAULT_VIEW_SUN = ['x' => 160.0, 'y' => 30.0];
    private const DEFAULT_VIEW_EARTH_RADIUS_PX = 26.0;
    private const DEFAULT_VIEW_PROBE_RADIUS_PX = [220.0, 280.0];
    private const WIDE_VIEW_SUN = ['x' => 160.0, 'y' => 180.0];
    private const WIDE_VIEW_PROBE_RADIUS_PX = [125.0, 145.0];

    /** @param array<string, array<string, mixed>> $probes */
    public function __construct(
        private readonly HorizonsClient $horizons,
        private readonly DsnClient $dsn,
        private readonly FileCache $cache,
        private readonly array $probes,
    ) {
    }

    /** Full view model for a probe's detail page. */
    public function getProbe(string $slug): array
    {
        $config = $this->probes[$slug] ?? throw new \InvalidArgumentException("Unknown probe: {$slug}");
        $live = $this->fetchLive($config);

        $activeInstruments = array_filter($config['instruments'], fn (array $i) => $i['active']);

        return [
            'slug' => $config['slug'],
            'name' => $config['name'],
            'launchDateLabel' => (new \DateTimeImmutable($config['launchDate']))->format('F j, Y'),
            'subtitleFact' => $config['subtitleFact'],
            'dishSize' => $config['dishSize'],
            'heading' => $config['heading'],
            'constellation' => $config['constellation'],
            'heliopauseCrossingLabel' => $config['heliopauseCrossingLabel'],
            'powerSource' => $config['powerSource'],
            'daysSinceLaunch' => Formatter::daysSinceFormatted($config['launchDate']),
            'location' => 'Interstellar space',
            'instruments' => $config['instruments'],
            'instrumentSummary' => sprintf('%d / %d instruments on', count($activeInstruments), count($config['instruments'])),
            'distanceFromSun' => Formatter::distanceKm($live['distanceFromSunKm']),
            'distanceFromEarth' => Formatter::distanceKm($live['distanceFromEarthKm']),
            'speed' => Formatter::speedKmS($live['speedKmS']),
            'oneWayLightTime' => Formatter::oneWayLightTime($live['lightTimeMinutes']),
            'roundTripLightTime' => Formatter::roundTripLightTime($live['lightTimeMinutes']),
            'signalLabel' => $live['inContact']
                ? "signal active \u{b7} DSN {$config['dishSize']} dish"
                : 'not currently in contact',
            'inContact' => $live['inContact'],
            'updatedLabel' => Formatter::relativeTimeAgo($live['fetchedAt']),
            'stale' => $live['stale'],
        ];
    }

    /** Lighter view model for each probe card on the home dashboard. */
    public function getSummary(string $slug): array
    {
        $full = $this->getProbe($slug);

        return [
            'slug' => $full['slug'],
            'name' => $full['name'],
            'distanceFromSun' => $full['distanceFromSun'],
            'distanceFromEarth' => $full['distanceFromEarth'],
            'speed' => $full['speed'],
            'oneWayLightTime' => $full['oneWayLightTime'],
            'daysSinceLaunch' => $full['daysSinceLaunch'],
            'location' => $full['location'],
            'constellation' => $full['constellation'],
            'instrumentSummary' => $full['instrumentSummary'],
            'signalLabel' => $full['inContact'] ? 'signal active' : 'not in contact',
            'inContact' => $full['inContact'],
            'updatedLabel' => $full['updatedLabel'],
            'stale' => $full['stale'],
        ];
    }

    /** Real-time layout for the home dashboard's solar-system orrery SVGs. */
    public function getOrreryLayout(): array
    {
        $v1 = $this->fetchLive($this->probes['voyager-1']);
        $v2 = $this->fetchLive($this->probes['voyager-2']);
        $earthEclipticLongitudeDeg = $this->getEarthEclipticLongitudeDeg();

        $v1Au = $v1['distanceFromSunKm'] / self::AU_IN_KM;
        $v2Au = $v2['distanceFromSunKm'] / self::AU_IN_KM;
        $minAu = min($v1Au, $v2Au);
        $maxAu = max($v1Au, $v2Au);

        $defaultRadiusV1 = Orrery::normalizeRadius($v1Au, $minAu, $maxAu, ...self::DEFAULT_VIEW_PROBE_RADIUS_PX);
        $defaultRadiusV2 = Orrery::normalizeRadius($v2Au, $minAu, $maxAu, ...self::DEFAULT_VIEW_PROBE_RADIUS_PX);
        $wideRadiusV1 = Orrery::normalizeRadius($v1Au, $minAu, $maxAu, ...self::WIDE_VIEW_PROBE_RADIUS_PX);
        $wideRadiusV2 = Orrery::normalizeRadius($v2Au, $minAu, $maxAu, ...self::WIDE_VIEW_PROBE_RADIUS_PX);

        return [
            'sun' => self::DEFAULT_VIEW_SUN,
            'earth' => Orrery::project($earthEclipticLongitudeDeg, self::DEFAULT_VIEW_EARTH_RADIUS_PX, ...array_values(self::DEFAULT_VIEW_SUN)),
            'v1' => Orrery::project($v1['eclipticLongitudeDeg'], $defaultRadiusV1, ...array_values(self::DEFAULT_VIEW_SUN)),
            'v2' => Orrery::project($v2['eclipticLongitudeDeg'], $defaultRadiusV2, ...array_values(self::DEFAULT_VIEW_SUN)),
            'wideSun' => self::WIDE_VIEW_SUN,
            'wideV1' => Orrery::project($v1['eclipticLongitudeDeg'], $wideRadiusV1, ...array_values(self::WIDE_VIEW_SUN)),
            'wideV2' => Orrery::project($v2['eclipticLongitudeDeg'], $wideRadiusV2, ...array_values(self::WIDE_VIEW_SUN)),
        ];
    }

    private function getEarthEclipticLongitudeDeg(): float
    {
        $data = $this->cache->remember('live-earth', fn () => [
            'eclipticLongitudeDeg' => $this->horizons->fetchEarthEclipticLongitude(),
        ]);

        return $data['eclipticLongitudeDeg'];
    }

    /** @param array<string, mixed> $config */
    private function fetchLive(array $config): array
    {
        return $this->cache->remember("live-{$config['slug']}", function () use ($config) {
            $sun = $this->horizons->fetchSunCentered($config['spkId']);
            $earth = $this->horizons->fetchEarthCentered($config['spkId']);
            $signal = $this->dsn->fetchSignalStatus($config['dsnSpacecraftId']);

            return [
                'distanceFromSunKm' => $sun['distanceFromSunKm'],
                'speedKmS' => $sun['speedKmS'],
                'eclipticLongitudeDeg' => $sun['eclipticLongitudeDeg'],
                'distanceFromEarthKm' => $earth['distanceFromEarthKm'],
                'lightTimeMinutes' => $earth['lightTimeMinutes'],
                'inContact' => $signal['inContact'],
                'dishName' => $signal['dishName'],
            ];
        });
    }
}
