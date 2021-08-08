<?php

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache;
use Memcached;
use Throwable;

use function defined;
use function getenv;
use function random_int;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

final class MemcachedTest extends AbstractCommonAdapterTest
{
    public function setUp(): void
    {
        $this->options = new Cache\Storage\Adapter\MemcachedOptions([
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

        $this->storage = new Cache\Storage\Adapter\Memcached();
        $this->storage->setOptions($this->options);
        $this->storage->flush();

        parent::setUp();
    }

    public function getCommonAdapterNamesProvider(): array
    {
        return [
            ['memcached'],
            ['Memcached'],
        ];
    }

    /**
     * @deprecated
     */
    public function testOptionsAddServer(): void
    {
        $options = new Cache\Storage\Adapter\MemcachedOptions();

        $deprecated = false;
        set_error_handler(function () use (&$deprecated) {
            $deprecated = true;
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
        $memcached = new Cache\Storage\Adapter\Memcached($options);
        $this->assertEquals($memcached->getOptions()->getServers(), $servers);
    }

    public function testMemcachedReturnsSuccessFalseOnError(): void
    {
        if (! defined('Memcached::GET_EXTENDED')) {
            $this->markTestSkipped('Test skipped because Memcached < 3.0 with Memcached::GET_EXTENDED not defined');
            return;
        }

        $resource        = $this->createMock(Memcached::class);
        $resourceManager = $this->createMock(Cache\Storage\Adapter\MemcachedResourceManager::class);

        $resourceManager
            ->method('getResource')
            ->willReturn($resource);

        $resource
            ->method('get')
            ->willReturn(null);

        $resource
            ->method('getResultCode')
            ->willReturn(Memcached::RES_PARTIAL_READ);

        $resource
            ->method('getResultMessage')
            ->willReturn('foo');

        $storage = new Cache\Storage\Adapter\Memcached([
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
        $options = new Cache\Storage\Adapter\MemcachedOptions();
        $options->setServers($servers);
        $this->assertEquals($expectedServers, $options->getServers());
    }

    public function testLibOptionsSet(): void
    {
        $options = new Cache\Storage\Adapter\MemcachedOptions();

        $options->setLibOptions([
            'COMPRESSION' => false,
        ]);

        $this->assertEquals($options->getResourceManager()->getLibOption(
            $options->getResourceId(),
            Memcached::OPT_COMPRESSION
        ), false);

        $memcached = new Cache\Storage\Adapter\Memcached($options);
        $this->assertEquals($memcached->getOptions()->getLibOptions(), [
            Memcached::OPT_COMPRESSION => false,
        ]);
    }

    /**
     * @deprecated
     */
    public function testLibOptionSet(): void
    {
        $options = new Cache\Storage\Adapter\MemcachedOptions();

        $deprecated = false;
        set_error_handler(function () use (&$deprecated) {
            $deprecated = true;
        }, E_USER_DEPRECATED);

        $options->setLibOption('COMPRESSION', false);

        restore_error_handler();
        $this->assertTrue($deprecated, 'Missing deprecated error');

        $this->assertEquals($options->getResourceManager()->getLibOption(
            $options->getResourceId(),
            Memcached::OPT_COMPRESSION
        ), false);

        $memcached = new Cache\Storage\Adapter\Memcached($options);
        $this->assertEquals($memcached->getOptions()->getLibOptions(), [
            Memcached::OPT_COMPRESSION => false,
        ]);
    }

    public function testOptionPersistentId(): void
    {
        $options         = new Cache\Storage\Adapter\MemcachedOptions();
        $resourceId      = $options->getResourceId();
        $resourceManager = $options->getResourceManager();
        $options->setPersistentId('testPersistentId');

        $this->assertSame('testPersistentId', $resourceManager->getPersistentId($resourceId));
        $this->assertSame('testPersistentId', $options->getPersistentId());
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenExceptionIsBeingDetectedByInternalMethod(): void
    {
        $memcached = new class ($this->options) extends Cache\Storage\Adapter\Memcached {
            /** @psalm-param positive-int $code  */
            public function createExceptionWithCode(int $code): Throwable
            {
                return $this->getExceptionByResultCode($code);
            }
        };

        $exception = $memcached->createExceptionWithCode(1);
        self::assertIsInt($exception->getCode());
        self::assertGreaterThan(0, $exception->getCode());
        self::assertInstanceOf(Cache\Exception\RuntimeException::class, $exception);
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenTotalSpaceRequestFails(): void
    {
        $memcached = $this
            ->getMockBuilder(Memcached::class)
            ->onlyMethods(['getStats', 'getResultMessage', 'getResultCode'])
            ->getMock();

        $memcached->method('getStats')->willReturn(false);
        $memcached->method('getResultMessage')->willReturn('Bar');
        $code = random_int(1, 999);
        $memcached->method('getResultCode')->willReturn($code);

        $options         = new Cache\Storage\Adapter\MemcachedOptions();
        $resourceManager = $this->createMock(Cache\Storage\Adapter\MemcachedResourceManager::class);
        $resourceManager
            ->method('getResource')
            ->willReturn($memcached);
        $options->setResourceManager($resourceManager);

        $storage = new Cache\Storage\Adapter\Memcached($options);

        $this->expectException(Cache\Exception\RuntimeException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage('Bar');
        $storage->getTotalSpace();
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenAvailableSpaceRequestFails(): void
    {
        $memcached = $this
            ->getMockBuilder(Memcached::class)
            ->onlyMethods(['getStats', 'getResultMessage', 'getResultCode'])
            ->getMock();

        $memcached->method('getStats')->willReturn(false);
        $memcached->method('getResultMessage')->willReturn('Foo');
        $code = random_int(1, 999);
        $memcached->method('getResultCode')->willReturn($code);

        $options         = new Cache\Storage\Adapter\MemcachedOptions();
        $resourceManager = $this->createMock(Cache\Storage\Adapter\MemcachedResourceManager::class);
        $resourceManager
            ->method('getResource')
            ->willReturn($memcached);
        $options->setResourceManager($resourceManager);

        $storage = new Cache\Storage\Adapter\Memcached($options);

        $this->expectException(Cache\Exception\RuntimeException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage('Foo');
        $storage->getAvailableSpace();
    }

    public function tearDown(): void
    {
        if ($this->storage) {
            $this->storage->flush();
        }

        parent::tearDown();
    }
}
