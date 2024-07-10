<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Memcached as MemcachedResource;

use function array_pop;
use function assert;
use function func_num_args;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;
use function substr;
use function time;

/**
 * @template-extends AbstractAdapter<MemcachedOptions>
 */
final class Memcached extends AbstractAdapter implements
    AvailableSpaceCapableInterface,
    FlushableInterface,
    TotalSpaceCapableInterface
{
    private const MAXIMUM_KEY_LENGTH = 250;

    /**
     * Has this instance be initialized
     */
    private bool $initialized = false;

    /**
     * The memcached resource manager
     */
    private ?MemcachedResourceManagerInterface $resourceManager = null;

    /**
     * The memcached resource id
     */
    private ?string $resourceId = null;

    /**
     * The namespace prefix
     */
    private string $namespacePrefix = '';

    /**
     * @param iterable<string,mixed>|MemcachedOptions|null $options
     */
    public function __construct(null|iterable|MemcachedOptions $options = null)
    {
        parent::__construct($options);

        // reset initialized flag on update option(s)
        $initialized = &$this->initialized;
        $this->getEventManager()->attach('option', static function () use (&$initialized): void {
            $initialized = false;
        });
    }

    /**
     * Initialize the internal memcached resource
     */
    private function getMemcachedResource(): MemcachedResource
    {
        $this->initialize();
        return $this->resourceManager->getResource($this->resourceId);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-assert MemcachedOptions $this->options
     */
    public function setOptions(iterable|AdapterOptions $options): self
    {
        if (! $options instanceof MemcachedOptions) {
            $options = new MemcachedOptions($options);
        }

        parent::setOptions($options);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions(): MemcachedOptions
    {
        $options = $this->options ?? new MemcachedOptions();
        if ($this->options === null) {
            $this->setOptions($options);
        }
        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        $memc = $this->getMemcachedResource();
        if (! $memc->flush()) {
            throw $this->getExceptionByResultCode($memc->getResultCode());
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalSpace(): int
    {
        $memc  = $this->getMemcachedResource();
        $stats = $memc->getStats();
        if ($stats === false) {
            throw new Exception\RuntimeException($memc->getResultMessage(), $memc->getResultCode());
        }

        $mem = array_pop($stats);
        return $mem['limit_maxbytes'];
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableSpace(): int
    {
        $memc  = $this->getMemcachedResource();
        $stats = $memc->getStats();
        if ($stats === false) {
            throw new Exception\RuntimeException($memc->getResultMessage(), $memc->getResultCode());
        }

        $mem = array_pop($stats);
        return $mem['limit_maxbytes'] - $mem['bytes'];
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetItem(string $normalizedKey, ?bool &$success = null, mixed &$casToken = null): mixed
    {
        $memc        = $this->getMemcachedResource();
        $internalKey = $this->namespacePrefix . $normalizedKey;

        if (func_num_args() > 2) {
            $output   = $memc->get($internalKey, null, MemcachedResource::GET_EXTENDED);
            $casToken = is_array($output) ? $output['cas'] : $casToken;
            $result   = is_array($output) ? $output['value'] : false;
        } else {
            $result = $memc->get($internalKey);
        }

        $success = true;
        if ($result === false) {
            $rsCode = $memc->getResultCode();
            if ($rsCode === MemcachedResource::RES_NOTFOUND) {
                $result  = null;
                $success = false;
            } elseif ($rsCode) {
                $success = false;
                throw $this->getExceptionByResultCode($rsCode);
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetItems(array $normalizedKeys): array
    {
        $memc = $this->getMemcachedResource();

        foreach ($normalizedKeys as &$normalizedKey) {
            $normalizedKey = $this->namespacePrefix . $normalizedKey;
        }

        $result = $memc->getMulti($normalizedKeys);
        if ($result === false) {
            throw $this->getExceptionByResultCode($memc->getResultCode());
        }

        // if $result is empty the loop below can be avoided
        // and HHVM returns NULL instead of an empty array in this case
        if (empty($result)) {
            return [];
        }

        // remove namespace prefix from result
        if ($this->namespacePrefix !== '') {
            $tmp            = [];
            $nsPrefixLength = strlen($this->namespacePrefix);
            foreach ($result as $internalKey => $value) {
                $tmp[substr($internalKey, $nsPrefixLength)] = $value;
            }
            $result = $tmp;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalHasItem(string $normalizedKey): bool
    {
        $memc  = $this->getMemcachedResource();
        $value = $memc->get($this->namespacePrefix . $normalizedKey);
        if ($value === false) {
            $rsCode = $memc->getResultCode();
            if ($rsCode === MemcachedResource::RES_SUCCESS) {
                return true;
            }

            if ($rsCode === MemcachedResource::RES_NOTFOUND) {
                return false;
            }

            throw $this->getExceptionByResultCode($rsCode);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalSetItem(string $normalizedKey, mixed $value): bool
    {
        $memc       = $this->getMemcachedResource();
        $expiration = $this->expirationTime();
        if (! $memc->set($this->namespacePrefix . $normalizedKey, $value, $expiration)) {
            throw $this->getExceptionByResultCode($memc->getResultCode());
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalSetItems(array $normalizedKeyValuePairs): array
    {
        $memc       = $this->getMemcachedResource();
        $expiration = $this->expirationTime();

        $namespacedKeyValuePairs = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $namespacedKeyValuePairs[$this->namespacePrefix . $normalizedKey] = $value;
        }

        if (! $memc->setMulti($namespacedKeyValuePairs, $expiration)) {
            throw $this->getExceptionByResultCode($memc->getResultCode());
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function internalAddItem(string $normalizedKey, mixed $value): bool
    {
        $memc       = $this->getMemcachedResource();
        $expiration = $this->expirationTime();
        if (! $memc->add($this->namespacePrefix . $normalizedKey, $value, $expiration)) {
            if ($memc->getResultCode() === MemcachedResource::RES_NOTSTORED) {
                return false;
            }
            throw $this->getExceptionByResultCode($memc->getResultCode());
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalReplaceItem(string $normalizedKey, mixed $value): bool
    {
        $memc       = $this->getMemcachedResource();
        $expiration = $this->expirationTime();
        if (! $memc->replace($this->namespacePrefix . $normalizedKey, $value, $expiration)) {
            $rsCode = $memc->getResultCode();
            if ($rsCode === MemcachedResource::RES_NOTSTORED) {
                return false;
            }
            throw $this->getExceptionByResultCode($rsCode);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalCheckAndSetItem(mixed $token, string $normalizedKey, mixed $value): bool
    {
        $memc       = $this->getMemcachedResource();
        $expiration = $this->expirationTime();
        $result     = $memc->cas($token, $this->namespacePrefix . $normalizedKey, $value, $expiration);

        if ($result === false) {
            $rsCode = $memc->getResultCode();
            if ($rsCode !== 0 && $rsCode !== MemcachedResource::RES_DATA_EXISTS) {
                throw $this->getExceptionByResultCode($rsCode);
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalRemoveItem(string $normalizedKey): bool
    {
        $memc   = $this->getMemcachedResource();
        $result = $memc->delete($this->namespacePrefix . $normalizedKey);

        if ($result === false) {
            $rsCode = $memc->getResultCode();
            if ($rsCode === MemcachedResource::RES_NOTFOUND) {
                return false;
            } elseif ($rsCode !== MemcachedResource::RES_SUCCESS) {
                throw $this->getExceptionByResultCode($rsCode);
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalRemoveItems(array $normalizedKeys): array
    {
        $memc = $this->getMemcachedResource();

        foreach ($normalizedKeys as &$normalizedKey) {
            $normalizedKey = $this->namespacePrefix . $normalizedKey;
        }

        $missingKeys = [];
        foreach ($memc->deleteMulti($normalizedKeys) as $key => $rsCode) {
            if ($rsCode !== true && $rsCode !== MemcachedResource::RES_SUCCESS) {
                if ($rsCode !== MemcachedResource::RES_NOTFOUND) {
                    throw $this->getExceptionByResultCode($rsCode);
                }
                $missingKeys[] = $key;
            }
        }

        // remove namespace prefix
        if ($missingKeys && $this->namespacePrefix !== '') {
            $nsPrefixLength = strlen($this->namespacePrefix);
            foreach ($missingKeys as &$missingKey) {
                $missingKey = substr($missingKey, $nsPrefixLength);
            }
        }

        return $missingKeys;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetCapabilities(): Capabilities
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        /**
         * @see MemcachedOptions::MAXIMUM_NAMESPACE_PREFIX_LENGTH
         * @see MemcachedOptions::getNamespaceSeparator()
         *
         * @var int<0,129> $keyLengthReservedForNamespaceWithPrefix
         */
        $keyLengthReservedForNamespaceWithPrefix = strlen($this->namespacePrefix);

        $maximumKeyLength = self::MAXIMUM_KEY_LENGTH - $keyLengthReservedForNamespaceWithPrefix;

        return $this->capabilities ??= new Capabilities(
            $maximumKeyLength,
            true,
            true,
            [
                'NULL'     => true,
                'boolean'  => true,
                'integer'  => true,
                'double'   => true,
                'string'   => true,
                'array'    => true,
                'object'   => 'object',
                'resource' => false,
            ],
            1,
            false,
        );
    }

    /**
     * Get expiration time by ttl
     *
     * Some storage commands involve sending an expiration value (relative to
     * an item or to an operation requested by the client) to the server. In
     * all such cases, the actual value sent may either be Unix time (number of
     * seconds since January 1, 1970, as an integer), or a number of seconds
     * starting from current time. In the latter case, this number of seconds
     * may not exceed 60*60*24*30 (number of seconds in 30 days); if the
     * expiration value is larger than that, the server will consider it to be
     * real Unix time value rather than an offset from current time.
     */
    private function expirationTime(): int
    {
        $ttl = $this->getOptions()->getTtl();
        if ($ttl > 2_592_000) {
            return (int) (time() + $ttl);
        }

        return (int) $ttl;
    }

    /**
     * Generate exception based of memcached result code
     *
     * @internal
     *
     * @param positive-int|0 $code
     * @throws Exception\InvalidArgumentException On success code.
     */
    public function getExceptionByResultCode(int $code): Exception\RuntimeException
    {
        switch ($code) {
            case MemcachedResource::RES_SUCCESS:
                throw new Exception\InvalidArgumentException(
                    "The result code '{$code}' (SUCCESS) isn't an error"
                );

            default:
                $resource     = $this->getMemcachedResource();
                $errorMessage = $resource->getLastErrorMessage();
                assert(is_string($errorMessage));
                return new Exception\RuntimeException(
                    sprintf('%s: %s', $resource->getResultMessage(), $errorMessage),
                    $code
                );
        }
    }

    /**
     * @psalm-assert MemcachedResourceManagerInterface $this->resourceManager
     * @psalm-assert string $this->resourceId
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $options = $this->getOptions();

        // get resource manager and resource id
        $this->resourceManager = $options->getResourceManager();
        $this->resourceId      = $options->getResourceId();

        // init namespace prefix
        $namespace = $options->getNamespace();
        if ($namespace !== '') {
            $this->namespacePrefix = $namespace . $options->getNamespaceSeparator();
        } else {
            $this->namespacePrefix = '';
        }

        $this->capabilities = null;

        // update initialized flag
        $this->initialized = true;
    }
}
