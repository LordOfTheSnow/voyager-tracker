<?php

declare(strict_types=1);

return [
    'cacheDir' => dirname(__DIR__) . '/var/cache',
    'cacheTtlSeconds' => 15 * 60,
    'refreshCadenceLabel' => 'refreshes every 15 minutes',
    'horizonsApiUrl' => 'https://ssd.jpl.nasa.gov/api/horizons.api',
    'dsnFeedUrl' => 'https://eyes.nasa.gov/dsn/data/dsn.xml',
    'httpTimeoutSeconds' => 10,
];
