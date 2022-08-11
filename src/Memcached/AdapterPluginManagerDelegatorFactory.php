<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Memcached;

use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\AdapterPluginManager;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Psr\Container\ContainerInterface;

use function assert;

final class AdapterPluginManagerDelegatorFactory
{
    public function __invoke(ContainerInterface $container, string $name, callable $callback): AdapterPluginManager
    {
        $pluginManager = $callback();
        assert($pluginManager instanceof AdapterPluginManager);

        $pluginManager->configure([
            'factories' => [
                Memcached::class => InvokableFactory::class,
            ],
            'aliases'   => [
                'memcached' => Memcached::class,
                'Memcached' => Memcached::class,
            ],
        ]);

        return $pluginManager;
    }
}
