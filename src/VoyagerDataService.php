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
    // Innermost ring (r=20, see home.twig) stands in for Earth's orbit.
    private const WIDE_VIEW_EARTH_RADIUS_PX = 20.0;
    // Pluto's orbit ring is drawn at r=130 (see home.twig); keep both probes
    // clearly past it, never overlapping the ring itself.
    private const WIDE_VIEW_PROBE_RADIUS_PX = [140.0, 158.0];
    // Ring radii for the wide view's outer bodies -- must match the <circle>
    // elements drawn in home.twig.
    private const WIDE_VIEW_PLANET_RADIUS_PX = [
        'jupiter' => 40.0,
        'saturn' => 62.0,
        'uranus' => 84.0,
        'neptune' => 106.0,
        'pluto' => 130.0,
    ];
    // spkId is each body's barycenter (steadier ephemeris than the planet
    // itself). Shared between the wide view (angle only) and the distance
    // modal (angle + real distance).
    private const HELIOCENTRIC_BODIES = [
        'earth' => ['spkId' => '399', 'label' => 'Earth'],
        'jupiter' => ['spkId' => '5', 'label' => 'Jupiter'],
        'saturn' => ['spkId' => '6', 'label' => 'Saturn'],
        'uranus' => ['spkId' => '7', 'label' => 'Uranus'],
        'neptune' => ['spkId' => '8', 'label' => 'Neptune'],
        'pluto' => ['spkId' => '9', 'label' => 'Pluto'],
    ];

    // Distance modal geometry: a much larger canvas, honestly showing all
    // bodies' real distances from the Sun on two alternate scales. Log scale
    // keeps everything visible and correctly ordered but isn't linearly
    // proportional; linear scale is a real proportional distance, which is
    // why the frontend gives it pan/zoom rather than a fixed viewport.
    // Canvas is square (see the modal's viewBox="0 0 900 900" in home.twig)
    // so panning/zooming is symmetric in every direction from the Sun.
    private const DISTANCE_MODAL_CENTER = ['x' => 450.0, 'y' => 450.0];
    private const DISTANCE_MODAL_LOG_PX_PER_LOG_AU = 170.0;
    private const DISTANCE_MODAL_LINEAR_PX_PER_AU = 4.5;

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
            'distanceFromSunPrecise' => Formatter::distanceKmPrecise($live['distanceFromSunKm']),
            'distanceFromEarth' => Formatter::distanceKm($live['distanceFromEarthKm']),
            'distanceFromEarthPrecise' => Formatter::distanceKmPrecise($live['distanceFromEarthKm']),
            'speed' => Formatter::speedKmS($live['speedKmS']),
            'speedKmH' => Formatter::speedKmH($live['speedKmS']),
            'speedMph' => Formatter::speedMph($live['speedKmS']),
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
        $earthEclipticLongitudeDeg = $this->getHeliocentricPosition('earth', self::HELIOCENTRIC_BODIES['earth']['spkId'])['eclipticLongitudeDeg'];

        $v1Au = $v1['distanceFromSunKm'] / self::AU_IN_KM;
        $v2Au = $v2['distanceFromSunKm'] / self::AU_IN_KM;
        $minAu = min($v1Au, $v2Au);
        $maxAu = max($v1Au, $v2Au);

        $defaultRadiusV1 = Orrery::normalizeRadius($v1Au, $minAu, $maxAu, ...self::DEFAULT_VIEW_PROBE_RADIUS_PX);
        $defaultRadiusV2 = Orrery::normalizeRadius($v2Au, $minAu, $maxAu, ...self::DEFAULT_VIEW_PROBE_RADIUS_PX);
        $wideRadiusV1 = Orrery::normalizeRadius($v1Au, $minAu, $maxAu, ...self::WIDE_VIEW_PROBE_RADIUS_PX);
        $wideRadiusV2 = Orrery::normalizeRadius($v2Au, $minAu, $maxAu, ...self::WIDE_VIEW_PROBE_RADIUS_PX);

        $widePlanets = [];
        foreach (self::WIDE_VIEW_PLANET_RADIUS_PX as $slug => $radiusPx) {
            $body = self::HELIOCENTRIC_BODIES[$slug];
            $position = $this->getHeliocentricPosition($slug, $body['spkId']);
            $widePlanets[$slug] = [
                ...Orrery::project($position['eclipticLongitudeDeg'], $radiusPx, ...array_values(self::WIDE_VIEW_SUN)),
                'label' => $body['label'],
            ];
        }

        return [
            'sun' => self::DEFAULT_VIEW_SUN,
            'earth' => Orrery::project($earthEclipticLongitudeDeg, self::DEFAULT_VIEW_EARTH_RADIUS_PX, ...array_values(self::DEFAULT_VIEW_SUN)),
            'v1' => Orrery::project($v1['eclipticLongitudeDeg'], $defaultRadiusV1, ...array_values(self::DEFAULT_VIEW_SUN)),
            'v2' => Orrery::project($v2['eclipticLongitudeDeg'], $defaultRadiusV2, ...array_values(self::DEFAULT_VIEW_SUN)),
            'wideSun' => self::WIDE_VIEW_SUN,
            'wideEarth' => Orrery::project($earthEclipticLongitudeDeg, self::WIDE_VIEW_EARTH_RADIUS_PX, ...array_values(self::WIDE_VIEW_SUN)),
            'wideV1' => Orrery::project($v1['eclipticLongitudeDeg'], $wideRadiusV1, ...array_values(self::WIDE_VIEW_SUN)),
            'wideV2' => Orrery::project($v2['eclipticLongitudeDeg'], $wideRadiusV2, ...array_values(self::WIDE_VIEW_SUN)),
            'widePlanets' => $widePlanets,
        ];
    }

    /**
     * Layout for the "real distances" modal: every tracked body's actual
     * position from the Sun, on two alternate scales (see the constants
     * above). Shares the same per-body cache entries as getOrreryLayout(),
     * so calling both on one page load costs no extra Horizons requests.
     */
    public function getDistanceModalLayout(): array
    {
        $v1 = $this->fetchLive($this->probes['voyager-1']);
        $v2 = $this->fetchLive($this->probes['voyager-2']);

        $bodies = [];
        foreach (self::HELIOCENTRIC_BODIES as $slug => $body) {
            $bodies[$slug] = [
                'label' => $body['label'],
                ...$this->getHeliocentricPosition($slug, $body['spkId']),
            ];
        }
        $bodies['v1'] = ['label' => 'V1', 'distanceFromSunKm' => $v1['distanceFromSunKm'], 'eclipticLongitudeDeg' => $v1['eclipticLongitudeDeg']];
        $bodies['v2'] = ['label' => 'V2', 'distanceFromSunKm' => $v2['distanceFromSunKm'], 'eclipticLongitudeDeg' => $v2['eclipticLongitudeDeg']];

        $log = ['sun' => [...self::DISTANCE_MODAL_CENTER, 'label' => 'Sun']];
        $linear = ['sun' => [...self::DISTANCE_MODAL_CENTER, 'label' => 'Sun']];

        foreach ($bodies as $slug => $body) {
            $distanceAu = $body['distanceFromSunKm'] / self::AU_IN_KM;
            $lon = $body['eclipticLongitudeDeg'];
            $point = ['label' => $body['label'], 'distanceLabel' => Formatter::distanceAu($distanceAu)];

            $logRadiusPx = Orrery::logRadius($distanceAu, self::DISTANCE_MODAL_LOG_PX_PER_LOG_AU);
            $linearRadiusPx = $distanceAu * self::DISTANCE_MODAL_LINEAR_PX_PER_AU;

            $log[$slug] = [...Orrery::project($lon, $logRadiusPx, ...array_values(self::DISTANCE_MODAL_CENTER)), 'radiusPx' => $logRadiusPx, ...$point];
            $linear[$slug] = [...Orrery::project($lon, $linearRadiusPx, ...array_values(self::DISTANCE_MODAL_CENTER)), 'radiusPx' => $linearRadiusPx, ...$point];
        }

        return ['log' => $log, 'linear' => $linear];
    }

    private function getHeliocentricPosition(string $cacheSlug, string $spkId): array
    {
        return $this->cache->remember("live-{$cacheSlug}", fn () => $this->horizons->fetchSunCentered($spkId));
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
