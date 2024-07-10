<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\Adapter\MemcachedOptions;
use Laminas\Cache\Storage\Adapter\MemcachedResourceManagerInterface;
use Memcached as MemcachedFromExtension;

use function assert;
use function bin2hex;
use function getenv;
use function random_bytes;
use function random_int;
use function reset;

/**
 * @template-extends AbstractCommonAdapterTest<MemcachedOptions,Memcached>
 */
final class MemcachedTest extends AbstractCommonAdapterTest
{
    public function setUp(): void
    {
        $this->options = new MemcachedOptions([
            'resource_id' => self::class,
        ]);

        if (
            getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST') !== false &&
            getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT') !== false
        ) {
            $this->options->getResourceManager()->setServers(self::class, [
                [getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST'), getenv('TESTS_LAMINAS_CACHE_MEMCACHED_PORT')],
            ]);
        } elseif (getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST') !== false) {
            $this->options->getResourceManager()->setServers(self::class, [
                [getenv('TESTS_LAMINAS_CACHE_MEMCACHED_HOST')],
            ]);
        }

        $this->storage = new Memcached();
        $this->storage->setOptions($this->options);

        parent::setUp();
    }

    public function testMemcachedReturnsSuccessFalseOnError(): void
    {
        $resource        = $this->createPartialMock(MemcachedFromExtension::class, [
            'get',
            'getResultCode',
            'getResultMessage',
            'getLastErrorMessage',
        ]);
        $resourceManager = $this->createMock(MemcachedResourceManagerInterface::class);

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

        $resource
            ->method('getLastErrorMessage')
            ->willReturn('bar');

        $storage = new Memcached([
            'resource_manager' => $resourceManager,
        ]);

        $storage
            ->getEventManager()->attach(
                'getItem.exception',
                static function (Cache\Storage\ExceptionEvent $e): void {
                    $e->setThrowException(false);
                    $e->stopPropagation(true);
                },
                -1
            );

        self::assertNull($storage->getItem('unknown', $success, $casToken));
        self::assertFalse($success);
        self::assertNull($casToken);
    }

    public static function getServersDefinitions(): array
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
        self::assertEquals($expectedServers, $options->getServers());
    }

    public function testLibOptionsSet(): void
    {
        $options = new MemcachedOptions();

        $options->setLibOptions([
            'COMPRESSION' => false,
        ]);

        self::assertEquals($options->getResourceManager()->getLibOption(
            $options->getResourceId(),
            MemcachedFromExtension::OPT_COMPRESSION
        ), false);

        $memcached = new Memcached($options);
        self::assertEquals($memcached->getOptions()->getLibOptions(), [
            MemcachedFromExtension::OPT_COMPRESSION => false,
        ]);
    }

    public function testOptionPersistentId(): void
    {
        $options         = new MemcachedOptions();
        $resourceId      = $options->getResourceId();
        $resourceManager = $options->getResourceManager();
        $options->setPersistentId('testPersistentId');

        self::assertSame('testPersistentId', $resourceManager->getPersistentId($resourceId));
        self::assertSame('testPersistentId', $options->getPersistentId());
    }

    public function testExceptionCodeIsPassedToRuntimeExceptionWhenExceptionIsBeingDetectedByInternalMethod(): void
    {
        /** @psalm-suppress InternalMethod */
        $exception = $this->storage->getExceptionByResultCode(1);
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
        $resourceManager = $this->createMock(MemcachedResourceManagerInterface::class);
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
        $resourceManager = $this->createMock(MemcachedResourceManagerInterface::class);
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
        $maximumKeyLength = $this->storage->getCapabilities()->maxKeyLength;
        $byteLength       = (int) ($maximumKeyLength / 2);
        self::assertGreaterThanOrEqual(1, $byteLength);
        assert($byteLength > 0);
        $key = bin2hex(random_bytes($byteLength));

        $value = 'whatever';
        self::assertTrue($this->storage->setItem($key, $value));
        self::assertEquals($value, $this->storage->getItem($key));
    }

    public function testCanReceiveServerListFromResource(): void
    {
        // Initialize memcached instance by persisting data to memcached
        self::assertTrue($this->storage->setItem('foo', 'bar'));
        $servers = $this->options->getResourceManager()->getServers($this->options->getResourceId());
        self::assertNotEmpty($servers);
        $server = reset($servers);
        self::assertArrayHasKey('host', $server);
        self::assertArrayHasKey('port', $server);
    }
}
