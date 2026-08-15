<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Parses NASA's official "DSN Now" live feed (the same XML the
 * eyes.nasa.gov/dsn-now visualization consumes). Dishes track many
 * missions at once and often aren't pointed at either Voyager at a given
 * moment -- that's not a fault, it's normal scheduling -- so "not currently
 * in contact" is a real, expected status, not an error.
 */
final class DsnClient
{
    public function __construct(
        private readonly string $feedUrl,
        private readonly int $timeoutSeconds,
    ) {
    }

    /** @return array{inContact: bool, dishName: ?string} */
    public function fetchSignalStatus(string $spacecraftId): array
    {
        return DsnFeedParser::findSignalStatus($this->fetchXml(), $spacecraftId);
    }

    private function fetchXml(): \SimpleXMLElement
    {
        $ch = curl_init($this->feedUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'voyager-tracker/1.0',
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("DSN Now request failed: {$error}");
        }
        if ($status !== 200) {
            throw new \RuntimeException("DSN Now request returned HTTP {$status}");
        }

        $xml = simplexml_load_string($body);
        if ($xml === false) {
            throw new \RuntimeException('DSN Now feed did not parse as XML.');
        }

        return $xml;
    }
}
