<?php

declare(strict_types=1);

namespace App\Support;

final class Formatter
{
    public static function distanceKm(float $km): string
    {
        return number_format($km / 1_000_000_000, 1) . 'B km';
    }

    public static function speedKmS(float $kmPerSecond): string
    {
        return number_format($kmPerSecond, 1) . ' km/s';
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
