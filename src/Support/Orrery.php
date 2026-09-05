<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts heliocentric ecliptic longitude (0-360 deg, increasing
 * counter-clockwise as viewed from the ecliptic north pole -- the
 * astronomical convention Horizons reports, and the same "looking down on
 * the solar system" viewpoint every orrery diagram in this app uses) into
 * flat 2D screen coordinates, and composes that into the layouts for all
 * three of the app's diagrams. Pure functions of already-fetched position
 * values (distances in AU, angles in degrees) -- no HTTP, no cache, no
 * knowledge of where those values came from, so it's fixture-testable the
 * same way HorizonsResponseParser and DsnFeedParser are.
 */
final class Orrery
{
    // Home dashboard orrery: a single real-scale diagram (Sun, Neptune's
    // orbital ring, the heliopause ring, and both probes), sized so the
    // farther-out probe sits at HOME_ORRERY_PROBE_RADIUS_PX from the Sun.
    // The heliopause ring is a full circle around the Sun -- symmetric by
    // definition -- but the probes (and their labels) only ever reach out in
    // whatever direction their real ecliptic longitude happens to be, so a
    // fixed square canvas wastes space on every side they don't reach. The
    // canvas is instead sized per-request to tightly wrap the Sun, the
    // heliopause ring, and both probes' real positions (plus label
    // clearance) -- see homeLayout(). It stays undistorted (rings circular,
    // not elliptical) because home.twig's <svg> viewBox and aspect-ratio
    // always share these exact dimensions, keeping the x/y pixel-per-unit
    // scale equal.
    private const HOME_ORRERY_PROBE_RADIUS_PX = 255.0;
    private const HOME_ORRERY_MARGIN_PX = 24.0;
    // Rough on-screen footprint of a probe's dot + "V1"/"V2" + AU labels
    // (see the fixed x+8/y+4/y+17 text offsets in home.twig), so the canvas
    // reaches far enough to fit the label, not just the bare dot.
    private const HOME_ORRERY_LABEL_RIGHT_PX = 58.0;
    private const HOME_ORRERY_LABEL_DOWN_PX = 30.0;

    // The heliopause isn't a sphere -- V1 crossed it at ~121 AU (2012), V2 at
    // ~119 AU (2018), and its true shape is a lopsided "windsock" pushed by
    // the interstellar medium. This ring is an illustrative average, same
    // spirit as the static instrument-health table: a labeled approximation,
    // not a live or precise boundary. Shared by every diagram that draws it.
    private const HELIOPAUSE_DISTANCE_AU = 120.0;

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

    // Per-probe "angle against the ecliptic" diagram (detail.twig's
    // Position & Heading card): an edge-on view -- the ecliptic plane drawn
    // as a horizontal line, the probe's trajectory as a straight ray from
    // the Sun at its real ecliptic latitude (project()'s angle/radius
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

    /**
     * Real-time, real-scale layout for the home dashboard's solar-system
     * orrery SVG. The canvas is sized to tightly fit whatever the live data
     * actually needs (see the class-level comment on the HOME_ORRERY_*
     * constants) rather than a fixed square, so it carries no built-in
     * padding on sides nothing reaches into.
     *
     * @param array{distanceAu: float, longitudeDeg: float} $v1
     * @param array{distanceAu: float, longitudeDeg: float} $v2
     * @param array{distanceAu: float, longitudeDeg: float} $neptune
     * @param array{distanceAu: float, longitudeDeg: float} $newHorizons
     */
    public static function homeLayout(array $v1, array $v2, array $neptune, array $newHorizons): array
    {
        $pxPerAu = self::HOME_ORRERY_PROBE_RADIUS_PX / max($v1['distanceAu'], $v2['distanceAu']);
        $heliopauseRadiusPx = self::HELIOPAUSE_DISTANCE_AU * $pxPerAu;
        $neptuneRadiusPx = $neptune['distanceAu'] * $pxPerAu;

        // Positions relative to the Sun at (0, 0), same convention project()
        // always uses (y grows downward, as in SVG).
        $v1Rel = self::project($v1['longitudeDeg'], $v1['distanceAu'] * $pxPerAu, 0.0, 0.0);
        $v2Rel = self::project($v2['longitudeDeg'], $v2['distanceAu'] * $pxPerAu, 0.0, 0.0);
        $neptuneRel = self::project($neptune['longitudeDeg'], $neptuneRadiusPx, 0.0, 0.0);
        $newHorizonsRel = self::project($newHorizons['longitudeDeg'], $newHorizons['distanceAu'] * $pxPerAu, 0.0, 0.0);

        // The "Heliopause (approx.)" label sits on the ring's upper-right
        // diagonal (see home.twig) -- its own rough footprint, in addition
        // to the ring itself, also has to fit inside the canvas.
        $heliopauseLabelRightPx = $heliopauseRadiusPx * 0.7071 + 94.0;
        $heliopauseLabelUpPx = $heliopauseRadiusPx * 0.7071;

        $reachRight = max($heliopauseRadiusPx, $heliopauseLabelRightPx, $v1Rel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX, $v2Rel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX, $neptuneRel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX, $newHorizonsRel['x'] + self::HOME_ORRERY_LABEL_RIGHT_PX);
        $reachLeft = max($heliopauseRadiusPx, -$v1Rel['x'], -$v2Rel['x'], -$neptuneRel['x'], -$newHorizonsRel['x']);
        $reachDown = max($heliopauseRadiusPx, $v1Rel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX, $v2Rel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX, $neptuneRel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX, $newHorizonsRel['y'] + self::HOME_ORRERY_LABEL_DOWN_PX);
        $reachUp = max($heliopauseRadiusPx, $heliopauseLabelUpPx, -$v1Rel['y'], -$v2Rel['y'], -$neptuneRel['y'], -$newHorizonsRel['y']);

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
            'neptune' => ['x' => $sun['x'] + $neptuneRel['x'], 'y' => $sun['y'] + $neptuneRel['y'], 'distanceLabel' => Formatter::distanceAu($neptune['distanceAu'])],
            'newHorizons' => ['x' => $sun['x'] + $newHorizonsRel['x'], 'y' => $sun['y'] + $newHorizonsRel['y'], 'distanceLabel' => Formatter::distanceAu($newHorizons['distanceAu'])],
            'v1' => ['x' => $sun['x'] + $v1Rel['x'], 'y' => $sun['y'] + $v1Rel['y'], 'distanceLabel' => Formatter::distanceAu($v1['distanceAu'])],
            'v2' => ['x' => $sun['x'] + $v2Rel['x'], 'y' => $sun['y'] + $v2Rel['y'], 'distanceLabel' => Formatter::distanceAu($v2['distanceAu'])],
        ];
    }

    /**
     * Layout for the "Solar system — distances" modal: every tracked body's actual
     * position from the Sun, on two alternate scales (see the constants
     * above).
     *
     * @param list<array{slug: string, label: string, distanceAu: float, longitudeDeg: float}> $bodies
     */
    public static function distanceModalLayout(array $bodies): array
    {
        $log = ['sun' => [...self::DISTANCE_MODAL_CENTER, 'label' => 'Sun']];
        $linear = ['sun' => [...self::DISTANCE_MODAL_CENTER, 'label' => 'Sun']];

        foreach ($bodies as $body) {
            $point = ['label' => $body['label'], 'distanceLabel' => Formatter::distanceAu($body['distanceAu'])];

            $logRadiusPx = self::logRadius($body['distanceAu'], self::DISTANCE_MODAL_LOG_PX_PER_LOG_AU);
            $linearRadiusPx = $body['distanceAu'] * self::DISTANCE_MODAL_LINEAR_PX_PER_AU;

            $log[$body['slug']] = [...self::project($body['longitudeDeg'], $logRadiusPx, ...array_values(self::DISTANCE_MODAL_CENTER)), 'radiusPx' => $logRadiusPx, ...$point];
            $linear[$body['slug']] = [...self::project($body['longitudeDeg'], $linearRadiusPx, ...array_values(self::DISTANCE_MODAL_CENTER)), 'radiusPx' => $linearRadiusPx, ...$point];
        }

        $heliopause = [
            'label' => 'Heliopause (approx.)',
            'distanceLabel' => Formatter::distanceAu(self::HELIOPAUSE_DISTANCE_AU),
            'logRadiusPx' => self::logRadius(self::HELIOPAUSE_DISTANCE_AU, self::DISTANCE_MODAL_LOG_PX_PER_LOG_AU),
            'linearRadiusPx' => self::HELIOPAUSE_DISTANCE_AU * self::DISTANCE_MODAL_LINEAR_PX_PER_AU,
        ];

        return ['log' => $log, 'linear' => $linear, 'heliopause' => $heliopause];
    }

    /**
     * Per-probe "angle against the ecliptic" diagram (detail.twig's
     * Position & Heading card). See the ECLIPTIC_DIAGRAM_* constants above.
     */
    public static function eclipticDiagram(float $latitudeDeg, bool $inContact): array
    {
        $sun = self::ECLIPTIC_DIAGRAM_SUN;
        $probe = self::project($latitudeDeg, self::ECLIPTIC_DIAGRAM_PROBE_RADIUS_PX, $sun['x'], $sun['y']);
        $arcLabel = self::project($latitudeDeg / 2, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX + 16.0, $sun['x'], $sun['y']);

        return [
            'sun' => $sun,
            'planeEndX' => self::ECLIPTIC_DIAGRAM_PLANE_END_X,
            'probe' => $probe,
            'probeLabelY' => $probe['y'] + ($latitudeDeg >= 0 ? -15.0 : 24.0),
            'arcStart' => self::project(0.0, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX, $sun['x'], $sun['y']),
            'arcEnd' => self::project($latitudeDeg, self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX, $sun['x'], $sun['y']),
            'arcRadius' => self::ECLIPTIC_DIAGRAM_ARC_RADIUS_PX,
            'arcSweepFlag' => $latitudeDeg >= 0 ? 0 : 1,
            'arcLabel' => $arcLabel,
            'angleLabel' => number_format(abs($latitudeDeg), 1) . "\u{b0}",
            'directionWord' => $latitudeDeg >= 0 ? 'above' : 'below',
            'heliopauseTop' => self::project(90.0, self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX, $sun['x'], $sun['y']),
            'heliopauseBottom' => self::project(-90.0, self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX, $sun['x'], $sun['y']),
            'heliopauseRadius' => self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX,
            'heliopauseLabelX' => $sun['x'] + self::ECLIPTIC_DIAGRAM_HELIOPAUSE_RADIUS_PX + 6.0,
            'inContact' => $inContact,
        ];
    }
}
