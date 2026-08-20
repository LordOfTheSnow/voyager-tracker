<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Lazy TTL cache backed by one JSON file per key. On a cache miss it calls
 * the producer; if the producer throws (upstream API down) and a stale file
 * still exists, that stale data is returned with `stale => true` rather than
 * failing the request.
 */
final class FileCache
{
    public function __construct(
        private readonly string $dir,
        private readonly int $ttlSeconds,
    ) {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @param callable(): array<string, mixed> $producer
     * @param int|null $ttlSeconds Overrides the constructor TTL for this key only --
     *     for entries that change far slower than the app's normal 15-minute
     *     cadence (e.g. a multi-year ephemeris scan) and would otherwise be
     *     refetched needlessly often.
     * @return array<string, mixed>
     */
    public function remember(string $key, callable $producer, ?int $ttlSeconds = null): array
    {
        $path = $this->pathFor($key);

        if ($this->isFresh($key, $ttlSeconds)) {
            return $this->read($path, stale: false);
        }

        try {
            $fresh = $producer();
            $fresh['fetchedAt'] = time();
            file_put_contents($path, json_encode($fresh, JSON_THROW_ON_ERROR), LOCK_EX);

            return $this->read($path, stale: false);
        } catch (\Throwable $e) {
            if (is_file($path)) {
                return $this->read($path, stale: true);
            }

            throw $e;
        }
    }

    /**
     * Whether a `remember()` call for this key would return cached data without
     * calling the producer -- i.e. without doing the (potentially slow) network
     * fetch. Lets callers decide to show a loading state *before* triggering a
     * refresh, rather than only finding out it was slow after the fact.
     */
    public function isFresh(string $key, ?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? $this->ttlSeconds;
        $path = $this->pathFor($key);

        return is_file($path) && (time() - filemtime($path)) < $ttl;
    }

    /** @return array<string, mixed> */
    private function read(string $path, bool $stale): array
    {
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $data['stale'] = $stale;

        return $data;
    }

    private function pathFor(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    }
}
