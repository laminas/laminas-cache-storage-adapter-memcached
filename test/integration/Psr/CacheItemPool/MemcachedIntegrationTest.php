<?php

namespace LaminasTest\Cache\Storage\Adapter\Psr\CacheItemPool;

use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractCacheItemPoolIntegrationTest;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function getenv;
use function sprintf;

class MemcachedIntegrationTest extends AbstractCacheItemPoolIntegrationTest
{
    /**
     * Backup default timezone
     *
     * @var string
     */
    private $tz;

    protected function setUp(): void
    {
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');

        $this->skippedTests['testBasicUsageWithLongKey']                        = 'Memcached adapter does not'
        . ' support keys up to 300 bytes.';
        $deferredSkippedMessage                                                 = sprintf(
            '%s storage doesn\'t support driver deferred',
            Memcached::class
        );
        $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired'] = $deferredSkippedMessage;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);

        parent::tearDown();
    }

    protected function createStorage(): StorageInterface
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

        return new Memcached($options);
    }
}
