<?php

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\Adapter\MemcachedOptions;
use Laminas\Cache\Storage\Adapter\MemcachedResourceManager;
use Memcached as MemcachedFromExtension;

use function bin2hex;
use function getenv;
use function random_bytes;
use function random_int;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * @template-extends AbstractCommonAdapterTest<Memcached,MemcachedOptions>
 */
final class MemcachedTest extends AbstractCommonAdapterTest
{
    public function setUp(): void
    {
        $this->options = new MemcachedOptions([
            'resource_id' => self::class,
        ]);

        if (getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST') && getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT')) {
            $this->options->getResourceManager()->setServers(self::class, [
                [getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST'), getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT')],
            ]);
        } elseif (getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST')) {
            $this->options->getResourceManager()->setServers(self::class, [
                [getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST')],
            ]);
        }

        $this->storage = new Memcached();
        $this->storage->setOptions($this->options);

        parent::setUp();
    }

    /**
     * @deprecated
     */
    public function testOptionsAddServer(): void
    {
        $options = new MemcachedOptions();

        $deprecated = false;
        set_error_handler(function () use (&$deprecated): bool {
            $deprecated = true;
            return true;
        }, E_USER_DEPRECATED);

        $options->addServer('127.0.0.1', 11211);
        $options->addServer('localhost');
        $options->addServer('domain.com', 11215);

        restore_error_handler();
        $this->assertTrue($deprecated, 'Missing deprecated error');

        $servers = [
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
            ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
            ['host' => 'domain.com', 'port' => 11215, 'weight' => 0],
        ];

        $this->assertEquals($options->getServers(), $servers);
        $memcached = new Memcached($options);
        $this->assertEquals($memcached->getOptions()->getServers(), $servers);
    }

    public function testMemcachedReturnsSuccessFalseOnError(): void
    {
        $resource        = $this->createPartialMock(MemcachedFromExtension::class, [
            'get',
            'getResultCode',
            'getResultMessage',
        ]);
        $resourceManager = $this->createMock(MemcachedResourceManager::class);

        $resourceManager
            ->method('getResource')
            ->willReturn($resource);

        $resource
            ->method('get')
            ->willReturn(null);

        $resource
            ->method('getResultCode')
            ->willReturn(MemcachedFromExtension::RES_PARTIAL_READ);

        $resource
            ->method('getResultMessage')
            ->willReturn('foo');

        $storage = new Memcached([
            'resource_manager' => $resourceManager,
        ]);

        $storage
            ->getEventManager()->attach(
                'getItem.exception',
                function (Cache\Storage\ExceptionEvent $e) {
                    $e->setThrowException(false);
                    $e->stopPropagation(true);
                },
                -1
            );

        $this->assertNull($storage->getItem('unknown', $success, $casToken));
        $this->assertFalse($success);
        $this->assertNull($casToken);
    }

    public function getServersDefinitions(): array
    {
        $expectedServers = [
            ['host' => '127.0.0.1', 'port' => 12345, 'weight' => 1],
            ['host' => 'localhost', 'port' => 54321, 'weight' => 2],
            ['host' => 'examp.com', 'port' => 11211, 'weight' => 0],
        ];

        return [
            // servers as array list
            [
                [
                    ['127.0.0.1', 12345, 1],
                    ['localhost', '54321', '2'],
                    ['examp.com'],
                ],
                $expectedServers,
            ],

            // servers as array assoc
            [
                [
                    ['127.0.0.1', 12345, 1],
                    ['localhost', '54321', '2'],
                    ['examp.com'],
                ],
                $expectedServers,
            ],

            // servers as string list
            [
                [
                    '127.0.0.1:12345?weight=1',
                    'localhost:54321?weight=2',
                    'examp.com',
                ],
                $expectedServers,
            ],

            // servers as string
            [
                '127.0.0.1:12345?weight=1, localhost:54321?weight=2,tcp://examp.com',
                $expectedServers,
            ],
        ];
    }

    /**
     * @param string|array $servers
     * @dataProvider getServersDefinitions
     */
    public function testOptionSetServers($servers, array $expectedServers): void
    {
        $options = new MemcachedOptions();
        $options->setServers($servers);
        $this->assertEquals($expectedServers, $options->getServers());
    }

    public function testLibOptionsSet(): void
    {
        $options = new MemcachedOptions();

        $options->setLibOptions([
            'COMPRESSION' => false,
        ]);

        $this->assertEquals($options->getResourceManager()->getLibOption(
            $options->getResourceId(),
            MemcachedFromExtension::OPT_COMPRESSION
        ), false);

        $memcached = new Memcached($options);
        $this->assertEquals($memcached->getOptions()->getLibOptions(), [
            MemcachedFromExtension::OPT_COMPRESSION => false,
        ]);
    }

    /**
     * @deprecated
     */
    public function testLibOptionSet(): void
    {
        $options = new MemcachedOptions();

        $deprecated = false;
        set_error_handler(function () use (&$deprecated): bool {
            $deprecated = true;
            return true;
        }, E_USER_DEPRECATED);

        $options->setLibOption('COMPRESSION', false);

        restore_error_handler();
        $this->assertTrue($deprecated, 'Missing deprecated error');

        $this->assertEquals($options->getResourceManager()->getLibOption(
            $options->getResourceId(),
            MemcachedFromExtension::OPT_COMPRESSION
        ), false);

        $memcached = new Memcached($options);
        $this->assertEquals($memcached->getOptions()->getLibOptions(), [
            MemcachedFromExtension::OPT_COMPRESSION => false,
        ]);
    }

    public function testOptionPersistentId(): void
    {
        $options         = new MemcachedOptions();
        $resourceId      = $options->getResourceId();
        $resourceManager = $options->getResourceManager();
        $options->setPersistentId('testPersistentId');

        $this->assertSame('testPersistentId', $resourceManager->getPersistentId($resourceId));
        $this->assertSame('testPersistentId', $options->getPersistentId());
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenExceptionIsBeingDetectedByInternalMethod(): void
    {
        /** @psalm-suppress InternalMethod */
        $exception = $this->storage->getExceptionByResultCode(1);
        self::assertIsInt($exception->getCode());
        self::assertGreaterThan(0, $exception->getCode());
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenTotalSpaceRequestFails(): void
    {
        $memcached = $this
            ->getMockBuilder(MemcachedFromExtension::class)
            ->onlyMethods(['getStats', 'getResultMessage', 'getResultCode'])
            ->getMock();

        $memcached->method('getStats')->willReturn(false);
        $memcached->method('getResultMessage')->willReturn('Bar');
        $code = random_int(1, 999);
        $memcached->method('getResultCode')->willReturn($code);

        $options         = new MemcachedOptions();
        $resourceManager = $this->createMock(MemcachedResourceManager::class);
        $resourceManager
            ->method('getResource')
            ->willReturn($memcached);
        $options->setResourceManager($resourceManager);

        $storage = new Memcached($options);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage('Bar');
        $storage->getTotalSpace();
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenAvailableSpaceRequestFails(): void
    {
        $memcached = $this
            ->getMockBuilder(MemcachedFromExtension::class)
            ->onlyMethods(['getStats', 'getResultMessage', 'getResultCode'])
            ->getMock();

        $memcached->method('getStats')->willReturn(false);
        $memcached->method('getResultMessage')->willReturn('Foo');
        $code = random_int(1, 999);
        $memcached->method('getResultCode')->willReturn($code);

        $options         = new MemcachedOptions();
        $resourceManager = $this->createMock(MemcachedResourceManager::class);
        $resourceManager
            ->method('getResource')
            ->willReturn($memcached);
        $options->setResourceManager($resourceManager);

        $storage = new Memcached($options);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage('Foo');
        $storage->getAvailableSpace();
    }

    public function testCanStoreValueWithKeyAtMaximumLength(): void
    {
        $maximumKeyLength = $this->storage->getCapabilities()->getMaxKeyLength();
        $key              = bin2hex(random_bytes((int) ($maximumKeyLength / 2)));

        $value = 'whatever';
        self::assertTrue($this->storage->setItem($key, $value));
        self::assertEquals($value, $this->storage->getItem($key));
    }

    public function testDecrementSameItemTwice(): void
    {
        $item = 'foo';
        $this->storage->decrementItem($item, 1);
        $this->storage->decrementItem($item, 1);
    }
}
