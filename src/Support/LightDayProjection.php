<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds when a probe's one-way light time will next cross a whole
 * light-day boundary (1 light day, then 2 once 1 is passed, and so on).
 *
 * This deliberately does NOT extrapolate from a single "as of now" speed
 * reading, the way the rest of this app approximates things (see
 * HorizonsClient's docblock on treating Sun-centered range-rate as total
 * speed) -- that approximation breaks down specifically here. Earth's own
 * ~29.8 km/s orbital motion makes a probe's *observed* recession rate (as
 * seen from Earth) swing between roughly -8 and +41 km/s over a year, so a
 * constant-rate projection can land months off for a probe already close
 * to a crossing. Instead this scans a real series of future Horizons
 * samples (HorizonsClient::fetchEarthLightTimeSeries) for the first one
 * that reaches the target, and linearly interpolates between that sample
 * and the one before it for a same-day-ish estimate.
 */
final class LightDayProjection
{
    private const LIGHT_DAY_MINUTES = 24 * 60;

    /**
     * @param list<array{date: int, lightTimeMinutes: float}> $series Must be
     *     sorted chronologically, starting at or before "now".
     * @return array{targetLightDays: int, date: \DateTimeImmutable}|null null if
     *     the crossing lies beyond the fetched series
     */
    public static function findCrossing(array $series, float $currentLightTimeMinutes): ?array
    {
        $targetLightDays = (int) floor($currentLightTimeMinutes / self::LIGHT_DAY_MINUTES) + 1;
        $targetMinutes = $targetLightDays * self::LIGHT_DAY_MINUTES;

        $previous = null;
        foreach ($series as $point) {
            if ($point['lightTimeMinutes'] >= $targetMinutes) {
                return [
                    'targetLightDays' => $targetLightDays,
                    'date' => $previous === null
                        ? self::toDate($point['date'])
                        : self::interpolate($previous, $point, $targetMinutes),
                ];
            }
            $previous = $point;
        }

        return null;
    }

    /** @param array{date: int, lightTimeMinutes: float} $before
     *  @param array{date: int, lightTimeMinutes: float} $after */
    private static function interpolate(array $before, array $after, float $targetMinutes): \DateTimeImmutable
    {
        $span = $after['lightTimeMinutes'] - $before['lightTimeMinutes'];
        if ($span <= 0.0) {
            return self::toDate($after['date']);
        }

        $t = ($targetMinutes - $before['lightTimeMinutes']) / $span;
        $secondsBetween = $after['date'] - $before['date'];

        return self::toDate($before['date'] + (int) round($t * $secondsBetween));
    }

    private static function toDate(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $timestamp);
    }
}
