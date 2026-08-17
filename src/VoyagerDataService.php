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
            'distanceFromSunAu' => Formatter::distanceAu($live['distanceFromSunKm'] / self::AU_IN_KM),
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
            'distanceFromSunAu' => $full['distanceFromSunAu'],
            'distanceFromEarth' => $full['distanceFromEarth'],
            'speed' => $full['speed'],
            'speedKmH' => $full['speedKmH'],
            'speedMph' => $full['speedMph'],
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
                'distanceFromEarthKm' => $earth['distanceFromEarthKm'],
                'lightTimeMinutes' => $earth['lightTimeMinutes'],
                'inContact' => $signal['inContact'],
                'dishName' => $signal['dishName'],
            ];
        });
    }
}
