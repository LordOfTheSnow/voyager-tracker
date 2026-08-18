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
    private const COMPLEXES = [
        ['min' => 10, 'max' => 29, 'name' => 'Goldstone', 'location' => 'Goldstone, California, USA', 'flag' => "\u{1F1FA}\u{1F1F8}"],
        ['min' => 30, 'max' => 49, 'name' => 'Canberra', 'location' => 'Canberra, Australia', 'flag' => "\u{1F1E6}\u{1F1FA}"],
        ['min' => 50, 'max' => 69, 'name' => 'Madrid', 'location' => 'Madrid, Spain', 'flag' => "\u{1F1EA}\u{1F1F8}"],
    ];

    /** @return array{complex: string, location: string, flag: string}|null */
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
                    'flag' => $complex['flag'],
                ];
            }
        }

        return null;
    }
}
