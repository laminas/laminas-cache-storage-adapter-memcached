<?php

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Adapter\MemcachedResourceManager;
use Memcached;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function count;
use function is_array;

/**
 * PHPUnit test case
 */

/**
 * @group      Laminas_Cache
 * @covers Laminas\Cache\Storage\Adapter\MemcachedResourceManager
 */
class MemcachedResourceManagerTest extends TestCase
{
    /**
     * The resource manager
     *
     * @var MemcachedResourceManager
     */
    protected $resourceManager;

    public function setUp()
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
     * @return array
     */
    public function validResourceProvider(): array
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
                class_exists('Memcached', false) ? [
                    Memcached::OPT_COMPRESSION => false,
                    Memcached::OPT_PREFIX_KEY  => 'test_',
                ] : [],
            ],

            // lib options given as constant value
            [
                'testLibOptionsGivenAsName',
                [
                    'lib_options' => class_exists('Memcached', false) ? [
                        Memcached::OPT_COMPRESSION => false,
                        Memcached::OPT_PREFIX_KEY  => 'test_',
                    ] : [],
                ],
                '',
                [],
                class_exists('Memcached', false) ? [
                    Memcached::OPT_COMPRESSION => false,
                    Memcached::OPT_PREFIX_KEY  => 'test_',
                ] : [],
            ],
        ];
    }

    /**
     * @dataProvider validResourceProvider
     * @param mixed  $resource
     * @param array  $expectedServers
     * @param array  $expectedLibOptions
     */
    public function testValidResources(
        string $resourceId,
        $resource,
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

        $this->assertSame($this->resourceManager, $this->resourceManager->setResource($resourceId, $resource));
        $this->assertTrue($this->resourceManager->hasResource($resourceId));

        $this->assertSame($expectedPersistentId, $this->resourceManager->getPersistentId($resourceId));
        $this->assertEquals($expectedServers, $this->resourceManager->getServers($resourceId));
        $this->assertEquals($expectedLibOptions, $this->resourceManager->getLibOptions($resourceId));

        $this->assertSame($this->resourceManager, $this->resourceManager->removeResource($resourceId));
        $this->assertFalse($this->resourceManager->hasResource($resourceId));
    }

    public function testSetLibOptionsOnExistingResource()
    {
        $memcachedInstalled = class_exists('Memcached', false);

        $libOptions   = ['compression' => false];
        $resourceId   = 'testResourceId';
        $resourceMock = $this->getMockBuilder('Memcached')
           ->setMethods(['setOptions'])
            ->getMock();

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
}
