<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Memcached as MemcachedResource;

/**
 * @psalm-type ServerArrayShape = array{host:string,port:int,weight?:int}
 */
interface MemcachedResourceManagerInterface
{
    /**
     * Get servers
     *
     * @return list<ServerArrayShape>
     * @throws Exception\RuntimeException
     */
    public function getServers(string $id): array;

    public function hasResource(string $id): bool;

    public function getResource(string $id): MemcachedResource;

    public function setResource(string $id, iterable|MemcachedResource $resource): void;

    public function removeResource(string $id): void;

    public function setPersistentId(string $id, string $persistentId): void;

    public function getPersistentId(string $id): string;

    public function setLibOptions(string $id, array $libOptions): void;

    public function getLibOptions(string $id): array;

    public function setLibOption(string $id, string|int $key, mixed $value): void;

    public function getLibOption(string $id, string|int $key): mixed;

    /**
     * Set servers
     *
     * $servers can be an array list or a comma separated list of servers.
     * One server in the list can be descripted as follows:
     * - URI:   [tcp://]<host>[:<port>][?weight=<weight>]
     * - Assoc: array('host' => <host>[, 'port' => <port>][, 'weight' => <weight>])
     * - List:  array(<host>[, <port>][, <weight>])
     */
    public function setServers(string $id, string|iterable $servers): void;

    public function addServers(string $id, string|iterable $servers): void;

    public function addServer(string $id, string|iterable $server): void;
}
