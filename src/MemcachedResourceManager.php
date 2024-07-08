<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Stdlib\ArrayUtils;
use Memcached as MemcachedResource;
use ReflectionClass;
use Traversable;

use function array_map;
use function array_merge;
use function array_udiff;
use function array_values;
use function assert;
use function constant;
use function defined;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function parse_str;
use function parse_url;
use function str_replace;
use function strpos;
use function strtoupper;
use function trim;

/**
 * @psalm-import-type ServerArrayShape from MemcachedResourceManagerInterface
 */
final class MemcachedResourceManager implements MemcachedResourceManagerInterface
{
    /**
     * Registered resources
     *
     * @var array<string,array|MemcachedResource>
     */
    private array $resources = [];

    public function getServers(string $id): array
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        assert(isset($this->resources[$id]));
        $resource = $this->resources[$id];

        if ($resource instanceof MemcachedResource) {
            return $resource->getServerList();
        }
        return $resource['servers'];
    }

    /**
     * Normalize one server into the following format:
     * array('host' => <host>, 'port' => <port>, 'weight' => <weight>)
     *
     * @throws Exception\InvalidArgumentException
     * @return ServerArrayShape
     */
    private function normalizeServer(string|iterable $server): array
    {
        $host   = null;
        $port   = 11211;
        $weight = 0;

        // convert a single server into an array
        if ($server instanceof Traversable) {
            $server = ArrayUtils::iteratorToArray($server);
        }

        if (is_array($server)) {
            // array(<host>[, <port>[, <weight>]])
            if (isset($server[0])) {
                $host   = (string) $server[0];
                $port   = isset($server[1]) ? (int) $server[1] : $port;
                $weight = isset($server[2]) ? (int) $server[2] : $weight;
            }

            // array('host' => <host>[, 'port' => <port>[, 'weight' => <weight>]])
            if (! isset($server[0]) && isset($server['host'])) {
                $host   = (string) $server['host'];
                $port   = isset($server['port']) ? (int) $server['port'] : $port;
                $weight = isset($server['weight']) ? (int) $server['weight'] : $weight;
            }
        } else {
            // parse server from URI host{:?port}{?weight}
            $server = trim($server);
            if (strpos($server, '://') === false) {
                $server = 'tcp://' . $server;
            }

            $server = parse_url($server);
            if (! is_array($server)) {
                throw new Exception\InvalidArgumentException("Invalid server given");
            }

            $host = $server['host'];
            $port = isset($server['port']) ? (int) $server['port'] : $port;

            if (isset($server['query'])) {
                $query = null;
                parse_str($server['query'], $query);
                if (isset($query['weight'])) {
                    $weight = (int) $query['weight'];
                }
            }
        }

        if (! is_string($host) || $host === '') {
            throw new Exception\InvalidArgumentException('Missing required server host');
        }

        return [
            'host'   => $host,
            'port'   => $port,
            'weight' => $weight,
        ];
    }

    public function hasResource(string $id): bool
    {
        return isset($this->resources[$id]);
    }

    public function getResource(string $id): MemcachedResource
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $resource = $this->resources[$id];
        if ($resource instanceof MemcachedResource) {
            return $resource;
        }

        if ($resource['persistent_id'] !== '') {
            $memc = new MemcachedResource($resource['persistent_id']);
        } else {
            $memc = new MemcachedResource();
        }

        $memc->setOptions($resource['lib_options']);

        // merge and add servers (with persistence id servers could be added already)
        $servers = array_udiff($resource['servers'], $memc->getServerList(), [$this, 'compareServers']);
        if ($servers) {
            $memc->addServers(array_values(array_map('array_values', $servers)));
        }

        // buffer and return
        $this->resources[$id] = $memc;
        return $memc;
    }

    public function setResource(string $id, iterable|MemcachedResource $resource): void
    {
        if (! $resource instanceof MemcachedResource) {
            if ($resource instanceof Traversable) {
                $resource = ArrayUtils::iteratorToArray($resource);
            }

            $resource = array_merge([
                'persistent_id' => '',
                'lib_options'   => [],
                'servers'       => [],
            ], $resource);

            // normalize and validate params
            $resource['lib_options'] = $this->normalizeLibOptions($resource['lib_options']);
            $resource['servers']     = $this->normalizeServers($resource['servers']);
        }

        $this->resources[$id] = $resource;
    }

    public function removeResource(string $id): void
    {
        unset($this->resources[$id]);
    }

    public function setPersistentId(string $id, string $persistentId): void
    {
        if (! $this->hasResource($id)) {
            $this->setResource($id, [
                'persistent_id' => $persistentId,
            ]);
            return;
        }

        assert(isset($this->resources[$id]));
        $resource = $this->resources[$id];
        if ($resource instanceof MemcachedResource) {
            throw new Exception\RuntimeException(
                "Can't change persistent id of resource {$id} after instanziation"
            );
        }

        $resource['persistent_id'] = $persistentId;
        $this->resources[$id]      = $resource;
    }

    public function getPersistentId(string $id): string
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        assert(isset($this->resources[$id]));
        $resource = $this->resources[$id];

        if ($resource instanceof MemcachedResource) {
            throw new Exception\RuntimeException(
                "Can't get persistent id of an instantiated memcached resource"
            );
        }

        return $resource['persistent_id'];
    }

    public function setLibOptions(string $id, array $libOptions): void
    {
        if (! $this->hasResource($id)) {
            $this->setResource($id, [
                'lib_options' => $libOptions,
            ]);
            return;
        }

        $libOptions = $this->normalizeLibOptions($libOptions);

        $resource = $this->resources[$id];
        if ($resource instanceof MemcachedResource) {
            $resource->setOptions($libOptions);
            return;
        }

        $resource['lib_options'] = $libOptions;
        $this->resources[$id]    = $resource;
    }

    public function getLibOptions(string $id): array
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        assert(isset($this->resources[$id]));
        $resource = $this->resources[$id];

        if ($resource instanceof MemcachedResource) {
            $libOptions = [];
            $reflection = new ReflectionClass('Memcached');
            $constants  = $reflection->getConstants();
            foreach ($constants as $constName => $constValue) {
                if (strpos($constName, 'OPT_') === 0) {
                    $libOptions[$constValue] = $resource->getOption($constValue);
                }
            }
            return $libOptions;
        }

        return $resource['lib_options'];
    }

    public function setLibOption(string $id, string|int $key, mixed $value): void
    {
        $this->setLibOptions($id, [$key => $value]);
    }

    public function getLibOption(string $id, string|int $key): mixed
    {
        if (! $this->hasResource($id)) {
            throw new Exception\RuntimeException("No resource with id '{$id}'");
        }

        $key      = $this->normalizeLibOptionKey($key);
        $resource = $this->resources[$id];

        if ($resource instanceof MemcachedResource) {
            return $resource->getOption($key);
        }

        return $resource['lib_options'][$key] ?? null;
    }

    /**
     * Normalize libmemcached options
     *
     * @throws Exception\InvalidArgumentException
     * @return array<int, mixed> $libOptions
     */
    private function normalizeLibOptions(iterable $libOptions): array
    {
        if (! is_array($libOptions) && ! $libOptions instanceof Traversable) {
            throw new Exception\InvalidArgumentException(
                "Lib-Options must be an array or an instance of Traversable"
            );
        }

        $result = [];
        foreach ($libOptions as $key => $value) {
            $key          = $this->normalizeLibOptionKey($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @throws Exception\InvalidArgumentException
     */
    private function normalizeLibOptionKey(string|int $key): int
    {
        if (is_int($key)) {
            return $key;
        }

        $const = 'Memcached::OPT_' . str_replace([' ', '-'], '_', strtoupper($key));
        if (! defined($const)) {
            throw new Exception\InvalidArgumentException("Unknown libmemcached option '{$key}' ({$const})");
        }
        $key = constant($const);
        assert(is_int($key));
        return $key;
    }

    public function setServers(string $id, string|iterable $servers): void
    {
        if (! $this->hasResource($id)) {
            $this->setResource($id, [
                'servers' => $servers,
            ]);
            return;
        }

        $servers = $this->normalizeServers($servers);

        $resource = $this->resources[$id];
        if ($resource instanceof MemcachedResource) {
            // don't add servers twice
            $servers = array_udiff($servers, $resource->getServerList(), [$this, 'compareServers']);
            if ($servers) {
                $resource->addServers($servers);
            }
            return;
        }

        $resource['servers']  = $servers;
        $this->resources[$id] = $resource;
    }

    public function addServers(string $id, string|iterable $servers): void
    {
        if (! $this->hasResource($id)) {
            $this->setResource($id, [
                'servers' => $servers,
            ]);

            return;
        }

        assert(isset($this->resources[$id]));
        $servers = $this->normalizeServers($servers);

        $resource = $this->resources[$id];
        if ($resource instanceof MemcachedResource) {
            // don't add servers twice
            $servers = array_udiff($servers, $resource->getServerList(), [$this, 'compareServers']);
            if ($servers) {
                $resource->addServers($servers);
            }

            return;
        }
        // don't add servers twice
        $resource['servers'] = array_merge(
            $resource['servers'],
            array_udiff($servers, $resource['servers'], [$this, 'compareServers'])
        );

        $this->resources[$id] = $resource;
    }

    public function addServer(string $id, string|iterable $server): void
    {
         $this->addServers($id, [$server]);
    }

    /**
     * Normalize a list of servers into the following format:
     * array(array('host' => <host>, 'port' => <port>, 'weight' => <weight>)[, ...])
     *
     * @return list<ServerArrayShape>
     */
    private function normalizeServers(string|iterable $servers): array
    {
        if (! is_array($servers) && ! $servers instanceof Traversable) {
            // Convert string into a list of servers
            $servers = explode(',', $servers);
        }

        $result = [];
        foreach ($servers as $server) {
            $server                                          = $this->normalizeServer($server);
            $result[$server['host'] . ':' . $server['port']] = $server;
        }

        return array_values($result);
    }

    /**
     * Compare 2 normalized server arrays
     * (Compares only the host and the port)
     */
    private function compareServers(array $serverA, array $serverB): int
    {
        $keyA = $serverA['host'] . ':' . $serverA['port'];
        $keyB = $serverB['host'] . ':' . $serverB['port'];
        return $keyA <=> $keyB;
    }
}
