<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandAttributeProviderInterface;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Psr\Container\ContainerInterface;
use Symfony\Component\Lock\LockFactory;

final class ResourceLockMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): ResourceLockMiddleware
    {
        return new ResourceLockMiddleware(
            lockFactory: $container->get(LockFactory::class),
            attributes: $container->has(CommandAttributeProviderInterface::class)
                ? $container->get(CommandAttributeProviderInterface::class)
                : null,
        );
    }
}
