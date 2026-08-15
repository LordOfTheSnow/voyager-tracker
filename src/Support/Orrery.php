<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts heliocentric ecliptic longitude (0-360 deg, increasing
 * counter-clockwise as viewed from the ecliptic north pole -- the
 * astronomical convention Horizons reports, and the same "looking down on
 * the solar system" viewpoint every orrery diagram in this app uses) into
 * flat 2D screen coordinates. It says nothing about which distance a given
 * pixel radius represents -- callers decide that scale per-diagram, since a
 * linear AU-to-pixel mapping can't hold both an inner planet and a Voyager
 * in the same viewBox.
 */
final class Orrery
{
    /** @return array{x: float, y: float} */
    public static function project(float $eclipticLongitudeDeg, float $radiusPx, float $centerX, float $centerY): array
    {
        $theta = deg2rad($eclipticLongitudeDeg);

        return [
            'x' => $centerX + $radiusPx * cos($theta),
            'y' => $centerY - $radiusPx * sin($theta),
        ];
    }

    /** Maps $value from [$valueMin, $valueMax] onto a [$radiusMin, $radiusMax] plot radius. */
    public static function normalizeRadius(float $value, float $valueMin, float $valueMax, float $radiusMin, float $radiusMax): float
    {
        if ($valueMax <= $valueMin) {
            return ($radiusMin + $radiusMax) / 2;
        }

        $t = ($value - $valueMin) / ($valueMax - $valueMin);

        return $radiusMin + $t * ($radiusMax - $radiusMin);
    }

    /**
     * Radius on a log10 distance scale: every body stays visible and stays
     * correctly ordered by distance, at the cost of the spacing between
     * bodies no longer being linearly proportional to their real distance
     * apart. $pxPerLogUnit sets how spread out the scale is; $auOffset
     * (default 1) keeps distances under 1 AU from going negative.
     */
    public static function logRadius(float $distanceAu, float $pxPerLogUnit, float $auOffset = 1.0): float
    {
        return $pxPerLogUnit * log10($auOffset + max(0.0, $distanceAu));
    }
}
