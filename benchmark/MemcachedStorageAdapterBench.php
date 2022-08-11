<?php

declare(strict_types=1);

namespace LaminasBench\Cache;

use Laminas\Cache\Storage\Adapter\Benchmark\AbstractStorageAdapterBenchmark;
use Laminas\Cache\Storage\Adapter\Memcached;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

use function getenv;

/**
 * @Revs(100)
 * @Iterations(10)
 * @Warmup(1)
 */
class MemcachedStorageAdapterBench extends AbstractStorageAdapterBenchmark
{
    public function __construct()
    {
        $host = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST') ?: '127.0.0.1';
        $port = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT') ?: '11211';

        $options = [
            'resource_id' => self::class,
        ];

        $options['servers'] = [[$host, $port]];

        parent::__construct(new Memcached($options));
    }

    /**
     * Skipped due to https://github.com/laminas/laminas-cache-storage-adapter-memcached/issues/17
     */
    public function benchDecrementMissingItemsSingle(): void
    {
    }

    /**
     * Skipped due to https://github.com/laminas/laminas-cache-storage-adapter-memcached/issues/17
     */
    public function benchDecrementMissingItemsBulk(): void
    {
    }
}
