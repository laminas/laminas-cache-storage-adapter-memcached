<?php

namespace LaminasTest\Cache\Psr\SimpleCache;

use Cache\IntegrationTests\SimpleCacheTest;
use Laminas\Cache\Exception;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\StorageFactory;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Psr\SimpleCache\CacheInterface;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function getenv;

/**
 * @require extension memcached
 */
class MemcachedIntegrationTest extends SimpleCacheTest
{
    /**
     * Backup default timezone
     *
     * @var string
     */
    private $tz;

    /** @var Memcached */
    private $storage;

    protected function setUp(): void
    {
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        $this->skippedTests['testBasicUsageWithLongKey'] = 'Memcached adapter does not support keys up to 300 bytes.';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);

        if ($this->storage) {
            $this->storage->flush();
        }

        parent::tearDown();
    }

    public function createSimpleCache(): CacheInterface
    {
        $host = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST');
        $port = getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT');

        $options = [
            'resource_id' => self::class,
        ];
        if ($host && $port) {
            $options['servers'] = [[$host, $port]];
        } elseif ($host) {
            $options['servers'] = [[$host]];
        }

        try {
            $storage = StorageFactory::adapterFactory('memcached', $options);
            return new SimpleCacheDecorator($storage);
        } catch (Exception\ExtensionNotLoadedException $e) {
            $this->markTestSkipped($e->getMessage());
        } catch (ServiceNotCreatedException $e) {
            if ($e->getPrevious() instanceof Exception\ExtensionNotLoadedException) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }
    }
}
