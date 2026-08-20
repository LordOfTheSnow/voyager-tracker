<?php

declare(strict_types=1);

// Overridable at runtime (e.g. the Docker image) via CACHE_TTL_SECONDS /
// HTTP_TIMEOUT_SECONDS env vars; getenv() returning false/'' (unset) falls
// back to the values below. Deliberately not using ?: here -- an explicit
// "0" override would be falsy and silently ignored by that operator.
$envInt = static function (string $name, int $default): int {
    $value = getenv($name);

    return $value !== false && $value !== '' ? (int) $value : $default;
};

$cacheTtlSeconds = $envInt('CACHE_TTL_SECONDS', 15 * 60);
$cacheTtlMinutes = intdiv($cacheTtlSeconds, 60);

return [
    // Keep in sync with composer.json's "version" and CHANGELOG.md's latest entry.
    'version' => '0.7.0',
    'cacheDir' => dirname(__DIR__) . '/var/cache',
    'cacheTtlSeconds' => $cacheTtlSeconds,
    // Derived from cacheTtlSeconds (rather than hardcoded) so it can't drift
    // out of sync when CACHE_TTL_SECONDS is overridden.
    'refreshCadenceLabel' => 'refreshes every ' . $cacheTtlMinutes . ' minute' . ($cacheTtlMinutes === 1 ? '' : 's'),
    'horizonsApiUrl' => 'https://ssd.jpl.nasa.gov/api/horizons.api',
    'dsnFeedUrl' => 'https://eyes.nasa.gov/dsn/data/dsn.xml',
    'httpTimeoutSeconds' => $envInt('HTTP_TIMEOUT_SECONDS', 10),
];
