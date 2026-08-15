<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/voyager-cache-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testCallsProducerOnFirstRead(): void
    {
        $cache = new FileCache($this->dir, ttlSeconds: 60);
        $calls = 0;

        $result = $cache->remember('key', function () use (&$calls) {
            $calls++;
            return ['value' => 42];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(42, $result['value']);
        $this->assertFalse($result['stale']);
    }

    public function testServesFreshCacheWithoutCallingProducerAgain(): void
    {
        $cache = new FileCache($this->dir, ttlSeconds: 60);
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;
            return ['value' => $calls];
        };

        $cache->remember('key', $producer);
        $second = $cache->remember('key', $producer);

        $this->assertSame(1, $calls);
        $this->assertSame(1, $second['value']);
    }

    public function testRefetchesAfterTtlExpires(): void
    {
        $cache = new FileCache($this->dir, ttlSeconds: 0);
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;
            return ['value' => $calls];
        };

        $cache->remember('key', $producer);
        sleep(1);
        $second = $cache->remember('key', $producer);

        $this->assertSame(2, $calls);
        $this->assertSame(2, $second['value']);
    }

    public function testFallsBackToStaleCacheWhenProducerThrows(): void
    {
        $cache = new FileCache($this->dir, ttlSeconds: 0);

        $cache->remember('key', fn () => ['value' => 'good']);
        sleep(1);

        $result = $cache->remember('key', function () {
            throw new \RuntimeException('upstream is down');
        });

        $this->assertSame('good', $result['value']);
        $this->assertTrue($result['stale']);
    }

    public function testRethrowsWhenProducerFailsAndNoCacheExistsYet(): void
    {
        $cache = new FileCache($this->dir, ttlSeconds: 60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upstream is down');

        $cache->remember('key', function () {
            throw new \RuntimeException('upstream is down');
        });
    }
}
