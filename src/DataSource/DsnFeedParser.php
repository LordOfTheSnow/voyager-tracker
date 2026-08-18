<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Pure parsing of the DSN Now XML feed, split out from DsnClient so it can
 * be unit tested against fixture XML without hitting the network.
 */
final class DsnFeedParser
{
    /**
     * @return array{
     *     inContact: bool,
     *     dishName: ?string,
     *     direction: ?string,
     *     signalType: ?string,
     *     dataRateBps: ?float,
     *     band: ?string,
     *     power: ?float,
     * }
     */
    public static function findSignalStatus(\SimpleXMLElement $xml, string $spacecraftId): array
    {
        foreach ($xml->dish as $dish) {
            foreach (['downSignal' => 'down', 'upSignal' => 'up'] as $tag => $direction) {
                foreach ($dish->{$tag} as $signal) {
                    $isActive = (string) $signal['active'] === 'true';
                    $matchesProbe = (string) $signal['spacecraftID'] === $spacecraftId;

                    if ($isActive && $matchesProbe) {
                        return [
                            'inContact' => true,
                            'dishName' => (string) $dish['name'],
                            'direction' => $direction,
                            'signalType' => isset($signal['signalType']) ? (string) $signal['signalType'] : null,
                            'dataRateBps' => isset($signal['dataRate']) ? (float) $signal['dataRate'] : null,
                            'band' => isset($signal['band']) ? (string) $signal['band'] : null,
                            'power' => isset($signal['power']) ? (float) $signal['power'] : null,
                        ];
                    }
                }
            }
        }

        return [
            'inContact' => false,
            'dishName' => null,
            'direction' => null,
            'signalType' => null,
            'dataRateBps' => null,
            'band' => null,
            'power' => null,
        ];
    }
}
