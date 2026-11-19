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
    // New Horizons only appears on the home orrery, as a third scale
    // reference alongside Neptune -- this is the Voyager tracker, not a
    // general probe tracker, so it deliberately has no probe card, detail
    // page, or entry in the distance modal's body list. -98 is its NAIF
    // spacecraft ID (same convention as the Voyagers' spkId above).
    private const NEW_HORIZONS_SPK_ID = '-98';

    // Light-day crossing projection (see LightDayProjection): a wide-span,
    // coarse-step future ephemeris scan, cached far longer than everything
    // else in this class since the answer barely shifts day to day.
    // The scan runs to the end of Horizons' Voyager trajectory data (it
    // has none past 2099-12-31). A 20-year window used to be enough, but
    // once Voyager 1 passed 1 light day (Nov 2026) its next target became
    // 2 light days, around 2076, so it needs the full range. That's about
    // 2,700 rows / ~190 KB per probe at 10-day steps, fetched once a day.
    private const LIGHT_DAY_SERIES_STOP_DATE = '2099-12-30';
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
        foreach ([...array_keys($this->probes), ...array_keys(self::HELIOCENTRIC_BODIES), 'new-horizons'] as $slug) {
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
            'missionDuration' => Formatter::yearsMonthsDaysSince($config['launchDate']),
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
            'eclipticDiagram' => Orrery::eclipticDiagram($live['eclipticLatitudeDeg'], $live['inContact']),
            'updatedLabel' => Formatter::relativeTimeAgo($live['fetchedAt']),
            'stale' => $live['stale'],
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
                // "-to2099" in the key forces a refetch on deploy, so an
                // already-cached 20-year series isn't kept for up to 24h.
                "lightday-series-to2099-{$spkId}",
                fn () => ['series' => $this->horizons->fetchEarthLightTimeSeries($spkId, self::LIGHT_DAY_SERIES_STOP_DATE, self::LIGHT_DAY_SERIES_STEP)],
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
     * orrery SVG. Fetches each body's position, then delegates the actual
     * canvas sizing (see Orrery::homeLayout() and its HOME_ORRERY_*
     * constants) to Orrery.
     */
    public function getOrreryLayout(): array
    {
        $v1 = $this->fetchLive($this->probes['voyager-1']);
        $v2 = $this->fetchLive($this->probes['voyager-2']);
        $neptune = $this->getHeliocentricPosition('neptune', self::HELIOCENTRIC_BODIES['neptune']['spkId']);
        $newHorizons = $this->getHeliocentricPosition('new-horizons', self::NEW_HORIZONS_SPK_ID);

        return Orrery::homeLayout(
            v1: $this->toPosition($v1),
            v2: $this->toPosition($v2),
            neptune: $this->toPosition($neptune),
            newHorizons: $this->toPosition($newHorizons),
        );
    }

    /**
     * Layout for the "Solar system — distances" modal: every tracked body's actual
     * position from the Sun, on two alternate scales (see the Orrery
     * constants). Shares the same per-body cache entries as
     * getOrreryLayout(), so calling both on one page load costs no extra
     * Horizons requests.
     */
    public function getDistanceModalLayout(): array
    {
        $v1 = $this->fetchLive($this->probes['voyager-1']);
        $v2 = $this->fetchLive($this->probes['voyager-2']);

        $bodies = [];
        foreach (self::HELIOCENTRIC_BODIES as $slug => $body) {
            $bodies[] = ['slug' => $slug, 'label' => $body['label'], ...$this->toPosition($this->getHeliocentricPosition($slug, $body['spkId']))];
        }
        $bodies[] = ['slug' => 'v1', 'label' => 'V1', ...$this->toPosition($v1)];
        $bodies[] = ['slug' => 'v2', 'label' => 'V2', ...$this->toPosition($v2)];

        return Orrery::distanceModalLayout($bodies);
    }

    /** @return array{distanceAu: float, longitudeDeg: float} */
    private function toPosition(array $live): array
    {
        return [
            'distanceAu' => $live['distanceFromSunKm'] / self::AU_IN_KM,
            'longitudeDeg' => $live['eclipticLongitudeDeg'],
        ];
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
