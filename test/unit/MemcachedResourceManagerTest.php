<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Adapter\MemcachedResourceManager;
use Memcached as MemcachedFromExtension;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function count;
use function is_array;

/**
 * @covers Laminas\Cache\Storage\Adapter\MemcachedResourceManager
 */
final class MemcachedResourceManagerTest extends TestCase
{
    /**
     * The resource manager
     */
    protected MemcachedResourceManager $resourceManager;

    public function setUp(): void
    {
        $this->resourceManager = new MemcachedResourceManager();
    }

    /**
     * Data provider to test valid resources
     *
     * Returns an array of the following structure:
     * array(array(
     *     <string resource id>,
     *     <mixed input resource>,
     *     <string normalized persistent id>,
     *     <array normalized lib options>,
     *     <array normalized server list>
     * )[, ...])
     *
     * @return list<array{0: string, 1: mixed, 2: string, 3: array, 4: array}>
     */
    public static function validResourceProvider(): array
    {
        return [
            // empty resource
            [
                'testEmptyResource',
                [],
                '',
                [],
                [],
            ],

            // stringify persistent id
            [
                'testStringifyPersistentId',
                ['persistent_id' => 1234],
                '1234',
                [],
                [],
            ],

            // servers given as string
            [
                'testServersGivenAsString',
                [
                    'servers' => '127.0.0.1:1234,127.0.0.1,192.1.0.1?weight=3,localhost,127.0.0.1:11211?weight=0',
                ],
                '',
                [
                    ['host' => '127.0.0.1', 'port' => 1234,  'weight' => 0],
                    ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
                    ['host' => '192.1.0.1', 'port' => 11211, 'weight' => 3],
                    ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
                ],
                [],
            ],

            // servers given as list of strings
            [
                'testServersGivenAsListOfStrings',
                [
                    'servers' => [
                        '127.0.0.1:1234',
                        '127.0.0.1',
                        '192.1.0.1?weight=3',
                        'localhost',
                        '127.0.0.1:11211?weight=0',
                    ],
                ],
                '',
                [
                    ['host' => '127.0.0.1', 'port' => 1234,  'weight' => 0],
                    ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
                    ['host' => '192.1.0.1', 'port' => 11211, 'weight' => 3],
                    ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
                ],
                [],
            ],

            // servers given as list of arrays
            [
                'testServersGivenAsListOfArrays',
                [
                    'servers' => [
                        ['127.0.0.1', 1234],
                        ['127.0.0.1'],
                        ['192.1.0.1', 11211, 3],
                        ['localhost'],
                        ['127.0.0.1', 11211, 0],
                    ],
                ],
                '',
                [
                    ['host' => '127.0.0.1', 'port' => 1234,  'weight' => 0],
                    ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
                    ['host' => '192.1.0.1', 'port' => 11211, 'weight' => 3],
                    ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
                ],
                [],
            ],

            // servers given as list of assoc arrays
            [
                'testServersGivenAsListOfAssocArrays',
                [
                    'servers' => [
                        [
                            'host' => '127.0.0.1',
                            'port' => 1234,
                        ],
                        [
                            'host' => '127.0.0.1',
                        ],
                        [
                            'host'   => '192.1.0.1',
                            'weight' => 3,
                        ],
                        [
                            'host' => 'localhost',
                        ],
                        [
                            'host'   => '127.0.0.1',
                            'port'   => 11211,
                            'weight' => 0,
                        ],
                    ],
                ],
                '',
                [
                    ['host' => '127.0.0.1', 'port' => 1234,  'weight' => 0],
                    ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
                    ['host' => '192.1.0.1', 'port' => 11211, 'weight' => 3],
                    ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
                ],
                [],
            ],

            // lib options given as name
            [
                'testLibOptionsGivenAsName',
                [
                    'lib_options' => [
                        'COMPRESSION' => false,
                        'PREFIX_KEY'  => 'test_',
                    ],
                ],
                '',
                [],
                [
                    MemcachedFromExtension::OPT_COMPRESSION => false,
                    MemcachedFromExtension::OPT_PREFIX_KEY  => 'test_',
                ],
            ],

            // lib options given as constant value
            [
                'testLibOptionsGivenAsName',
                [
                    'lib_options' => [
                        MemcachedFromExtension::OPT_COMPRESSION => false,
                        MemcachedFromExtension::OPT_PREFIX_KEY  => 'test_',
                    ],
                ],
                '',
                [],
                [
                    MemcachedFromExtension::OPT_COMPRESSION => false,
                    MemcachedFromExtension::OPT_PREFIX_KEY  => 'test_',
                ],
            ],
        ];
    }

    /**
     * @dataProvider validResourceProvider
     */
    public function testValidResources(
        string $resourceId,
        mixed $resource,
        string $expectedPersistentId,
        array $expectedServers,
        array $expectedLibOptions
    ) {
        // php-memcached is required to set libmemcached options
        if (is_array($resource) && isset($resource['lib_options']) && count($resource['lib_options']) > 0) {
            if (! class_exists('Memcached', false)) {
                $this->expectException(InvalidArgumentException::class);
                $this->expectExceptionMessage('Unknown libmemcached option');
            }
        }

        $this->resourceManager->setResource($resourceId, $resource);
        self::assertTrue($this->resourceManager->hasResource($resourceId));

        self::assertSame($expectedPersistentId, $this->resourceManager->getPersistentId($resourceId));
        self::assertEquals($expectedServers, $this->resourceManager->getServers($resourceId));
        self::assertEquals($expectedLibOptions, $this->resourceManager->getLibOptions($resourceId));

        $this->resourceManager->removeResource($resourceId);
        self::assertFalse($this->resourceManager->hasResource($resourceId));
    }

    public function testSetLibOptionsOnExistingResource()
    {
        $memcachedInstalled = class_exists('Memcached', false);

        $libOptions   = ['compression' => false];
        $resourceId   = 'testResourceId';
        $resourceMock = $this->createMock(MemcachedFromExtension::class);

        if (! $memcachedInstalled) {
            $this->expectException(InvalidArgumentException::class);
        } else {
            $resourceMock
                ->expects($this->once())
                ->method('setOptions')
                ->with($this->isType('array'));
        }

        $this->resourceManager->setResource($resourceId, $resourceMock);
        $this->resourceManager->setLibOptions($resourceId, $libOptions);
    }

    public function testOptionsAddServer(): void
    {
        $resourceManager = $this->resourceManager;
        $resourceManager->addServer('foo', ['127.0.0.1', 11211]);
        $resourceManager->addServer('foo', 'localhost');
        $resourceManager->addServer('foo', ['domain.com', 11215]);

        $servers = [
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
            ['host' => 'localhost', 'port' => 11211, 'weight' => 0],
            ['host' => 'domain.com', 'port' => 11215, 'weight' => 0],
        ];

        self::assertEquals($servers, $resourceManager->getServers('foo'));
    }

    public function testLibOptionSet(): void
    {
        $resourceManager = $this->resourceManager;
        $resourceManager->setLibOption('foo', 'COMPRESSION', false);

        self::assertFalse($resourceManager->getLibOption(
            'foo',
            MemcachedFromExtension::OPT_COMPRESSION
        ));
    }
}
