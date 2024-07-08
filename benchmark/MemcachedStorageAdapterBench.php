<?php

declare(strict_types=1);

namespace LaminasBench\Cache;

use Laminas\Cache\Storage\Adapter\Benchmark\AbstractStorageAdapterBenchmark;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\Adapter\MemcachedOptions;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

use function getenv;

/**
 * @template-extends AbstractStorageAdapterBenchmark<MemcachedOptions>
 */
#[Revs(100)]
#[Iterations(10)]
#[Warmup(1)]
final class MemcachedStorageAdapterBench extends AbstractStorageAdapterBenchmark
{
    public function __construct()
    {
        $host = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST');
        if ($host === false) {
            $host = '127.0.0.1';
        }

        $port = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT');
        if ($port === false) {
            $port = 11211;
        }

        $options = [
            'resource_id' => self::class,
        ];

        $options['servers'] = [[$host, (int) $port]];

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
