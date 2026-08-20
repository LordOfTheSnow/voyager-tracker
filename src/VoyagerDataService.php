<?php

declare(strict_types=1);

namespace App;

use App\Cache\FileCache;
use App\DataSource\DsnClient;
use App\DataSource\HorizonsClient;
use App\Support\DsnStation;
use App\Support\Formatter;
use App\Support\LightDayProjection;
use App\Support\Orrery;

final class VoyagerDataService
{
    private const AU_IN_KM = 149_597_870.7;

    // Home dashboard orrery: a single real-scale diagram (Sun, Neptune's
    // orbital ring, the heliopause ring, and both probes), sized so the
    // farther-out probe sits at HOME_ORRERY_PROBE_RADIUS_PX from the Sun.
    // The heliopause ring is a full circle around the Sun -- symmetric by
    // definition -- but the probes (and their labels) only ever reach out in
    // whatever direction their real ecliptic longitude happens to be, so a
    // fixed square canvas wastes space on every side they don't reach. The
    // canvas is instead sized per-request to tightly wrap the Sun, the
    // heliopause ring, and both probes' real positions (plus label
    // clearance) -- see getOrreryLayout(). It stays undistorted (rings
    // circular, not elliptical) because home.twig's <svg> viewBox and
    // aspect-ratio always share these exact dimensions, keeping the x/y
    // pixel-per-unit scale equal.
    private const HOME_ORRERY_PROBE_RADIUS_PX = 255.0;
    private const HOME_ORRERY_MARGIN_PX = 24.0;
    // Rough on-screen footprint of a probe's dot + "V1"/"V2" + AU labels
    // (see the fixed x+8/y+4/y+17 text offsets in home.twig), so the canvas
    // reaches far enough to fit the label, not just the bare dot.
    private const HOME_ORRERY_LABEL_RIGHT_PX = 58.0;
    private const HOME_ORRERY_LABEL_DOWN_PX = 30.0;
    // spkId is each body's barycenter (steadier ephemeris than the planet
    // itself). Shared between the home orrery (Neptune only) and the
    // distance modal (every body, angle + real distance).
    private const HELIOCENTRIC_BODIES = [
        'earth' => ['spkId' => '399', 'label' => 'Earth'],
        'mars' => ['spkId' => '4', 'label' => 'Mars'],
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
    // The heliopause isn't a sphere -- V1 crossed it at ~121 AU (2012), V2 at
    // ~119 AU (2018), and its true shape is a lopsided "windsock" pushed by
    // the interstellar medium. This ring is an illustrative average, same
    // spirit as the static instrument-health table: a labeled approximation,
    // not a live or precise boundary.
    private const HELIOPAUSE_DISTANCE_AU = 120.0;

    // Per-probe "angle against the ecliptic" diagram (detail.twig's
    // Position & Heading card): an edge-on view -- the ecliptic plane drawn
    // as a horizontal line, the probe's trajectory as a straight ray from
    // the Sun at its real ecliptic latitude (Orrery::project's angle/radius
    // transform works unchanged here; latitude-from-horizontal is the same
    // math as longitude-from-a-reference-direction). Distance along the ray
    // is schematic, same spirit as the rest of this diagram always had --
    // only the angle is real, and it barely drifts year to year since both
    // Voyagers are now moving essentially radially outward.
    private const ECLIPTIC_DIAGRAM_SUN = ['x' => 55.0, 'y' => 130.0];
    private const ECLIPTIC_DIAGRAM_PLANE_END_X = 370.0;
    private const ECLIPTIC_DIAGRAM_PROBE_RADIUS_PX = 150.0;
    private const ECLIPTIC_DIAGRAM_ARC_RADIUS_PX = 42.0;
    // Sits strictly between the Sun and the probe dot (both probes are
    // schematically the same distance out, see above) so it reads as "the
    // boundary this probe already crossed" without claiming an exact
    // to-scale distance -- same illustrative-average spirit as
    // HELIOPAUSE_DISTANCE_AU above, just drawn as the right-side half of it
    // (the diagram has nothing to the left of the Sun to draw the other
    // half against).
    private const ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX = 95.0;

    // Light-day crossing projection (see LightDayProjection): a wide-span,
    // coarse-step future ephemeris scan, cached far longer than everything
    // else in this class since the answer barely shifts day to day.
    // 20 years / 10-day steps comfortably covers both probes' crossings
    // (V2's is the slower of the two) in one ~700-row Horizons request.
    private const LIGHT_DAY_SERIES_SPAN_DAYS = 20 * 365;
    private const LIGHT_DAY_SERIES_STEP = '10d';
    private const LIGHT_DAY_SERIES_CACHE_TTL_SECONDS = 24 * 60 * 60;

    /** @param array<string, array<string, mixed>> $probes */
    public function __construct(
        private readonly HorizonsClient $horizons,
        private readonly DsnClient $dsn,
        private readonly FileCache $cache,
        private readonly array $probes,
    ) {
    }

    /**
     * Whether getProbe() for this probe would resolve without a live fetch --
     * lets the front controller show a loading page instead of blocking the
     * request on JPL Horizons / DSN Now.
     */
    public function isProbeDataFresh(string $slug): bool
    {
        return $this->cache->isFresh("live-{$slug}");
    }

    /** Same check for everything getSummary()/getOrreryLayout()/getDistanceModalLayout() touch. */
    public function isHomeDataFresh(): bool
    {
        foreach ([...array_keys($this->probes), ...array_keys(self::HELIOCENTRIC_BODIES)] as $slug) {
            if (!$this->cache->isFresh("live-{$slug}")) {
                return false;
            }
        }

        return true;
    }

    /** Full view model for a probe's detail page. */
    public function getProbe(string $slug): array
    {
        $config = $this->probes[$slug] ?? throw new \InvalidArgumentException("Unknown probe: {$slug}");
        $live = $this->fetchLive($config);
        $station = $live['inContact'] ? DsnStation::locate($live['dishName']) : null;
        $directionLabel = match ($live['direction']) {
            'down' => 'Downlink',
            'up' => 'Uplink',
            default => null,
        };

        $activeInstruments = array_filter($config['instruments'], fn (array $i) => $i['active']);

        $lightDayCrossing = LightDayProjection::findCrossing(
            $this->getLightDayCrossingSeries($config['spkId']),
            $live['lightTimeMinutes'],
        );
        $lightDayCrossingLabel = null;
        $lightDayCrossingShortLabel = null;
        if ($lightDayCrossing !== null) {
            $unit = $lightDayCrossing['targetLightDays'] === 1 ? 'light day' : 'light days';
            $lightDayCrossingLabel = "Reaches {$lightDayCrossing['targetLightDays']} {$unit}: " . Formatter::dateTimeUtc($lightDayCrossing['date']);
            $lightDayCrossingShortLabel = "{$lightDayCrossing['targetLightDays']} ld: " . Formatter::dateOnly($lightDayCrossing['date']);
        }

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
            'distanceFromSunAu' => Formatter::distanceAu($live['distanceFromSunKm'] / self::AU_IN_KM),
            'distanceFromSunMiPrecise' => Formatter::distanceMiPrecise($live['distanceFromSunKm']),
            'distanceFromEarth' => Formatter::distanceKm($live['distanceFromEarthKm']),
            'distanceFromEarthPrecise' => Formatter::distanceKmPrecise($live['distanceFromEarthKm']),
            'distanceFromEarthAu' => Formatter::distanceAu($live['distanceFromEarthKm'] / self::AU_IN_KM),
            'distanceFromEarthMiPrecise' => Formatter::distanceMiPrecise($live['distanceFromEarthKm']),
            'speed' => Formatter::speedKmS($live['speedKmS']),
            'speedKmH' => Formatter::speedKmH($live['speedKmS']),
            'speedMph' => Formatter::speedMph($live['speedKmS']),
            'oneWayLightTime' => Formatter::oneWayLightTime($live['lightTimeMinutes']),
            'roundTripLightTime' => Formatter::roundTripLightTime($live['lightTimeMinutes']),
            'lightDayCrossingLabel' => $lightDayCrossingLabel,
            'lightDayCrossingShortLabel' => $lightDayCrossingShortLabel,
            'signalLabel' => $live['inContact']
                ? "signal active \u{b7} DSN {$config['dishSize']} dish"
                : 'not currently in contact',
            'inContact' => $live['inContact'],
            'dishFlagSrc' => $station['flagSrc'] ?? null,
            'dishFlagAlt' => $station['flagAlt'] ?? null,
            'dishLocation' => $station['location'] ?? null,
            'dsnDishName' => $live['dishName'],
            'dsnDirectionLabel' => $directionLabel,
            'dsnSignalType' => $live['signalType'] !== null ? ucfirst($live['signalType']) : null,
            'dsnBand' => $live['band'] !== null ? "{$live['band']}-band" : null,
            'dsnBandFrequency' => $live['band'] !== null ? Formatter::bandFrequency($live['band'], $live['direction']) : null,
            'dsnDataRateLabel' => $live['dataRateBps'] !== null ? Formatter::dataRate($live['dataRateBps']) : null,
            'dsnDataRateContext' => $live['dataRateBps'] !== null ? Formatter::dataRateContext($live['dataRateBps']) : null,
            'dsnPowerLabel' => ($live['power'] !== null && $live['direction'] !== null)
                ? Formatter::signalPower($live['power'], $live['direction'])
                : null,
            'dsnPowerContext' => ($live['power'] !== null && $live['direction'] !== null)
                ? Formatter::signalPowerContext($live['power'], $live['direction'])
                : null,
            'eclipticDiagram' => $this->getEclipticDiagram($live['eclipticLatitudeDeg'], $live['inContact']),
            'updatedLabel' => Formatter::relativeTimeAgo($live['fetchedAt']),
            'stale' => $live['stale'],
        ];
    }

    private function getEclipticDiagram(float $latitudeDeg, bool $inContact): array
    {
        $sun = self::ECLIPTIC_DIAGRAM_SUN;
        $probe = Orrery::project($latitudeDeg, self::ECLIPTIC_DIAGRAM_PROBE_RADIUS_PX, $sun['x'], $sun['y']);
        $arcLabel = Orrery::project($latitudeDeg / 2, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX + 16.0, $sun['x'], $sun['y']);

        return [
            'sun' => $sun,
            'planeEndX' => self::ECLIPTIC_DIAGRAM_PLANE_END_X,
            'probe' => $probe,
            'probeLabelY' => $probe['y'] + ($latitudeDeg >= 0 ? -15.0 : 24.0),
            'arcStart' => Orrery::project(0.0, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX, $sun['x'], $sun['y']),
            'arcEnd' => Orrery::project($latitudeDeg, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX, $sun['x'], $sun['y']),
            'arcRadius' => self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX,
            'arcSweepFlag' => $latitudeDeg >= 0 ? 0 : 1,
            'arcLabel' => $arcLabel,
            'angleLabel' => number_format(abs($latitudeDeg), 1) . "\u{b0}",
            'directionWord' => $latitudeDeg >= 0 ? 'above' : 'below',
            'heliopauseTop' => Orrery::project(90.0, self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX, $sun['x'], $sun['y']),
            'heliopauseBottom' => Orrery::project(-90.0, self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX, $sun['x'], $sun['y']),
            'heliopauseRadius' => self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX,
            'heliopauseLabelX' => $sun['x'] + self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX + 6.0,
            'inContact' => $inContact,
        ];
    }

    /**
     * Best-effort: this is supplementary/illustrative information, not core
     * data, so a Horizons failure here (including the very first request,
     * before there's any stale cache to fall back on -- FileCache rethrows
     * in that case) must not break the rest of the page.
     *
     * @return list<array{date: int, lightTimeMinutes: float}>
     */
    private function getLightDayCrossingSeries(string $spkId): array
    {
        try {
            $cached = $this->cache->remember(
                "lightday-series-{$spkId}",
                fn () => ['series' => $this->horizons->fetchEarthLightTimeSeries($spkId, self::LIGHT_DAY_SERIES_SPAN_DAYS, self::LIGHT_DAY_SERIES_STEP)],
                self::LIGHT_DAY_SERIES_CACHE_TTL_SECONDS,
            );

            return $cached['series'];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Lighter view model for each probe card on the home dashboard. */
    public function getSummary(string $slug): array
    {
        $full = $this->getProbe($slug);

        return [
            'slug' => $full['slug'],
            'name' => $full['name'],
            'distanceFromSun' => $full['distanceFromSun'],
            'distanceFromSunAu' => $full['distanceFromSunAu'],
            'distanceFromEarth' => $full['distanceFromEarth'],
            'speed' => $full['speed'],
            'speedKmH' => $full['speedKmH'],
            'speedMph' => $full['speedMph'],
            'oneWayLightTime' => $full['oneWayLightTime'],
            'lightDayCrossingShortLabel' => $full['lightDayCrossingShortLabel'],
            'daysSinceLaunch' => $full['daysSinceLaunch'],
            'location' => $full['location'],
            'constellation' => $full['constellation'],
            'instrumentSummary' => $full['instrumentSummary'],
            'signalLabel' => $full['inContact'] ? 'signal active' : 'not in contact',
            'inContact' => $full['inContact'],
            'dishFlagSrc' => $full['dishFlagSrc'],
            'dishFlagAlt' => $full['dishFlagAlt'],
            'dishLocation' => $full['dishLocation'],
            'updatedLabel' => $full['updatedLabel'],
            'stale' => $full['stale'],
        ];
    }

    /**
     * Real-time, real-scale layout for the home dashboard's solar-system
     * orrery SVG. The canvas is sized to tightly fit whatever the live data
     * actually needs (see the class-level comment on the HOME_ORRERY_*
     * constants) rather than a fixed square, so it carries no built-in
     * padding on sides nothing reaches into.
     */
    public function getOrreryLayout(): array
    {
        $v1 = $this->fetchLive($this->probes['voyager-1']);
        $v2 = $this->fetchLive($this->probes['voyager-2']);
        $neptune = $this->getHeliocentricPosition('neptune', self::HELIOCENTRIC_BODIES['neptune']['spkId']);
        $neptuneAu = $neptune['distanceFromSunKm'] / self::AU_IN_KM;

        $v1Au = $v1['distanceFromSunKm'] / self::AU_IN_KM;
        $v2Au = $v2['distanceFromSunKm'] / self::AU_IN_KM;
        $pxPerAu = self::HOME_ORRERY_PROBE_RADIUS_PX / max($v1Au, $v2Au);
        $heliopauseRadiusPx = self::HELIOPAUSE_DISTANCE_AU * $pxPerAu;
        $neptuneRadiusPx = $neptuneAu * $pxPerAu;

        // Positions relative to the Sun at (0, 0), same convention Orrery::project
        // always uses (y grows downward, as in SVG).
        $v1Rel = Orrery::project($v1['eclipticLongitudeDeg'], $v1Au * $pxPerAu, 0.0, 0.0);
        $v2Rel = Orrery::project($v2['eclipticLongitudeDeg'], $v2Au * $pxPerAu, 0.0, 0.0);
        $neptuneRel = Orrery::project($neptune['eclipticLongitudeDeg'], $neptuneRadiusPx, 0.0, 0.0);

        // The "Heliopause (approx.)" label sits on the ring's upper-right
        // diagonal (see home.twig) -- its own rough footprint, in addition
        // to the ring itself, also has to fit inside the canvas.
        $heliopauseLabelRightPx = $heliopauseRadiusPx * 0.7071 + 94.0;
        $heliopauseLabelUpPx = $heliopauseRadiusPx * 0.7071;

        $reachRight = max($heliopauseRadiusPx, $heliopauseLabelRightPx, $v1Rel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX, $v2Rel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX, $neptuneRel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX);
        $reachLeft = max($heliopauseRadiusPx, -$v1Rel['x'], -$v2Rel['x'], -$neptuneRel['x']);
        $reachDown = max($heliopauseRadiusPx, $v1Rel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX, $v2Rel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX, $neptuneRel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX);
        $reachUp = max($heliopauseRadiusPx, $heliopauseLabelUpPx, -$v1Rel['y'], -$v2Rel['y'], -$neptuneRel['y']);

        $sun = [
            'x' => $reachLeft + self::HOME_ORRERY_MARGIN_PX,
            'y' => $reachUp + self::HOME_ORRERY_MARGIN_PX,
        ];

        return [
            'sun' => $sun,
            'canvasWidth' => $reachLeft + $reachRight + 2 * self::HOME_ORRERY_MARGIN_PX,
            'canvasHeight' => $reachUp + $reachDown + 2 * self::HOME_ORRERY_MARGIN_PX,
            'neptuneRadiusPx' => $neptuneRadiusPx,
            'heliopauseRadiusPx' => $heliopauseRadiusPx,
            'neptune' => ['x' => $sun['x'] + $neptuneRel['x'], 'y' => $sun['y'] + $neptuneRel['y'], 'distanceLabel' => Formatter::distanceAu($neptuneAu)],
            'v1' => ['x' => $sun['x'] + $v1Rel['x'], 'y' => $sun['y'] + $v1Rel['y'], 'distanceLabel' => Formatter::distanceAu($v1Au)],
            'v2' => ['x' => $sun['x'] + $v2Rel['x'], 'y' => $sun['y'] + $v2Rel['y'], 'distanceLabel' => Formatter::distanceAu($v2Au)],
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

        $heliopause = [
            'label' => 'Heliopause (approx.)',
            'distanceLabel' => Formatter::distanceAu(self::HELIOPAUSE_DISTANCE_AU),
            'logRadiusPx' => Orrery::logRadius(self::HELIOPAUSE_DISTANCE_AU, self::DISTANCE_MODAL_LOG_PX_PER_LOG_AU),
            'linearRadiusPx' => self::HELIOPAUSE_DISTANCE_AU * self::DISTANCE_MODAL_LINEAR_PX_PER_AU,
        ];

        return ['log' => $log, 'linear' => $linear, 'heliopause' => $heliopause];
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
                'eclipticLatitudeDeg' => $sun['eclipticLatitudeDeg'],
                'distanceFromEarthKm' => $earth['distanceFromEarthKm'],
                'lightTimeMinutes' => $earth['lightTimeMinutes'],
                'inContact' => $signal['inContact'],
                'dishName' => $signal['dishName'],
                'direction' => $signal['direction'],
                'signalType' => $signal['signalType'],
                'dataRateBps' => $signal['dataRateBps'],
                'band' => $signal['band'],
                'power' => $signal['power'],
            ];
        });
    }
}
