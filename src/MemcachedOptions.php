<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;

use function sprintf;
use function strlen;

/**
 * These are options specific to the Memcached adapter
 *
 * @psalm-import-type ServerArrayShape from MemcachedResourceManagerInterface
 */
final class MemcachedOptions extends AdapterOptions
{
    public const MAXIMUM_NAMESPACE_PREFIX_LENGTH = 128;
    // @codingStandardsIgnoreStart
    /**
     * Prioritized properties ordered by prio to be set first
     * in case a bulk of options sets set at once
     *
     * @var string[]
     */
    protected array $__prioritizedProperties__ = ['resource_manager', 'resource_id'];
    // @codingStandardsIgnoreEnd

    /**
     * The namespace separator
     */
    protected string $namespaceSeparator = ':';

    /**
     * The memcached resource manager
     */
    protected ?MemcachedResourceManagerInterface $resourceManager = null;

    /**
     * The resource id of the resource manager
     */
    protected string $resourceId = 'default';

    /**
     * Set namespace.
     *
     * The option Memcached::OPT_PREFIX_KEY will be used as the namespace.
     * It can't be longer than 128 characters.
     *
     * @see AdapterOptions::setNamespace()
     * @see MemcachedOptions::setPrefixKey()
     */
    public function setNamespace(string $namespace): self
    {
        if (self::MAXIMUM_NAMESPACE_PREFIX_LENGTH < strlen($namespace)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects a prefix key of no longer than 128 characters',
                __METHOD__
            ));
        }

        parent::setNamespace($namespace);
        return $this;
    }

    public function setNamespaceSeparator(string $namespaceSeparator): self
    {
        if ($this->namespaceSeparator !== $namespaceSeparator) {
            $this->triggerOptionEvent('namespace_separator', $namespaceSeparator);
            $this->namespaceSeparator = $namespaceSeparator;
        }
        return $this;
    }

    public function getNamespaceSeparator(): string
    {
        return $this->namespaceSeparator;
    }

    /**
     * Set the memcached resource manager to use
     */
    public function setResourceManager(?MemcachedResourceManagerInterface $resourceManager = null): self
    {
        if ($this->resourceManager !== $resourceManager) {
            $this->triggerOptionEvent('resource_manager', $resourceManager);
            $this->resourceManager = $resourceManager;
        }
        return $this;
    }

    public function getResourceManager(): MemcachedResourceManagerInterface
    {
        if (! $this->resourceManager) {
            $this->resourceManager = new MemcachedResourceManager();
        }
        return $this->resourceManager;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): self
    {
        if ($this->resourceId !== $resourceId) {
            $this->triggerOptionEvent('resource_id', $resourceId);
            $this->resourceId = $resourceId;
        }
        return $this;
    }

    public function getPersistentId(): string
    {
        return $this->getResourceManager()->getPersistentId($this->getResourceId());
    }

    public function setPersistentId(string $persistentId): self
    {
        $this->triggerOptionEvent('persistent_id', $persistentId);
        $this->getResourceManager()->setPersistentId($this->getResourceId(), $persistentId);
        return $this;
    }

    /**
     * Set a list of memcached servers to add on initialize
     *
     * @throws Exception\InvalidArgumentException
     */
    public function setServers(string|iterable $servers): self
    {
        $this->getResourceManager()->setServers($this->getResourceId(), $servers);
        return $this;
    }

    /**
     * @return list<ServerArrayShape>
     */
    public function getServers(): array
    {
        return $this->getResourceManager()->getServers($this->getResourceId());
    }

    /**
     * Set libmemcached options
     *
     * @link http://php.net/manual/memcached.constants.php
     */
    public function setLibOptions(array $libOptions): self
    {
        $this->getResourceManager()->setLibOptions($this->getResourceId(), $libOptions);
        return $this;
    }

    /**
     * Get libmemcached options
     *
     * @link http://php.net/manual/memcached.constants.php
     */
    public function getLibOptions(): array
    {
        return $this->getResourceManager()->getLibOptions($this->getResourceId());
    }
}
