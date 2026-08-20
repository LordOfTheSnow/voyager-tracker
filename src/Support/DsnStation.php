<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maps a DSN dish name (e.g. "DSS43") to its ground complex. Dish numbers
 * are assigned in fixed per-complex ranges (see
 * https://eyes.nasa.gov/dsn/dsn.html) rather than listing every dish
 * individually, so newly commissioned dishes within a complex still
 * resolve correctly.
 */
final class DsnStation
{
    // Self-hosted SVGs rather than Unicode flag emoji (U+1F1FA U+1F1F8 etc.) --
    // Windows deliberately ships no color-flag glyphs in its default emoji font
    // (Chrome/Edge on Windows fall back to showing the raw two-letter code as
    // text), so real flag emoji only render as pictures on some OS/browser
    // combinations. Only three complexes exist, so three small SVGs is cheap.
    private const COMPLEXES = [
        ['min' => 10, 'max' => 29, 'name' => 'Goldstone', 'location' => 'Goldstone, California, USA', 'flagSrc' => '/assets/img/flags/us.svg', 'flagAlt' => 'US flag'],
        ['min' => 30, 'max' => 49, 'name' => 'Canberra', 'location' => 'Canberra, Australia', 'flagSrc' => '/assets/img/flags/au.svg', 'flagAlt' => 'Australian flag'],
        ['min' => 50, 'max' => 69, 'name' => 'Madrid', 'location' => 'Madrid, Spain', 'flagSrc' => '/assets/img/flags/es.svg', 'flagAlt' => 'Spanish flag'],
    ];

    /** @return array{complex: string, location: string, flagSrc: string, flagAlt: string}|null */
    public static function locate(?string $dishName): ?array
    {
        if ($dishName === null || !preg_match('/DSS-?(\d+)/i', $dishName, $matches)) {
            return null;
        }

        $number = (int) $matches[1];

        foreach (self::COMPLEXES as $complex) {
            if ($number >= $complex['min'] && $number <= $complex['max']) {
                return [
                    'complex' => $complex['name'],
                    'location' => $complex['location'],
                    'flagSrc' => $complex['flagSrc'],
                    'flagAlt' => $complex['flagAlt'],
                ];
            }
        }

        return null;
    }
}
