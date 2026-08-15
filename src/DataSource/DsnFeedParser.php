<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Pure parsing of the DSN Now XML feed, split out from DsnClient so it can
 * be unit tested against fixture XML without hitting the network.
 */
final class DsnFeedParser
{
    /** @return array{inContact: bool, dishName: ?string} */
    public static function findSignalStatus(\SimpleXMLElement $xml, string $spacecraftId): array
    {
        foreach ($xml->dish as $dish) {
            foreach (['downSignal', 'upSignal'] as $signalType) {
                foreach ($dish->{$signalType} as $signal) {
                    $isActive = (string) $signal['active'] === 'true';
                    $matchesProbe = (string) $signal['spacecraftID'] === $spacecraftId;

                    if ($isActive && $matchesProbe) {
                        return [
                            'inContact' => true,
                            'dishName' => (string) $dish['name'],
                        ];
                    }
                }
            }
        }

        return ['inContact' => false, 'dishName' => null];
    }
}
