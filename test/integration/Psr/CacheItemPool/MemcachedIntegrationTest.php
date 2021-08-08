<?php

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Cache\IntegrationTests\CachePoolTest;
use Laminas\Cache\Exception;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\StorageFactory;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Psr\Cache\CacheItemPoolInterface;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function get_class;
use function getenv;
use function sprintf;

/**
 * @require extension memcached
 */
class MemcachedIntegrationTest extends CachePoolTest
{
    /**
     * Backup default timezone
     *
     * @var string
     */
    private $tz;

    /** @var Memcached */
    private $storage;

    protected function setUp()
    {
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');

        parent::setUp();
    }

    protected function tearDown()
    {
        date_default_timezone_set($this->tz);

        if ($this->storage) {
            $this->storage->flush();
        }

        parent::tearDown();
    }

    public function createCachePool(): CacheItemPoolInterface
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

            $deferredSkippedMessage                                                 = sprintf(
                '%s storage doesn\'t support driver deferred',
                get_class($storage)
            );
            $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired'] = $deferredSkippedMessage;

            return new CacheItemPoolDecorator($storage);
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
