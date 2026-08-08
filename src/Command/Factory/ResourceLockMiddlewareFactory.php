<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use LogicException;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Psr\Container\ContainerInterface;
use Symfony\Component\Lock\LockFactory;

final class ResourceLockMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): ResourceLockMiddleware
    {
        $lockFactory = $container->get(LockFactory::class);

        if (!$lockFactory instanceof LockFactory) {
            throw new LogicException(sprintf(
                'Container entry "%s" must be a %s instance.',
                LockFactory::class,
                LockFactory::class,
            ));
        }

        $metadata = null;

        if ($container->has(CommandMetadataProviderInterface::class)) {
            $metadata = $container->get(CommandMetadataProviderInterface::class);

            if (!$metadata instanceof CommandMetadataProviderInterface) {
                throw new LogicException(sprintf(
                    'Container entry "%s" must implement %s.',
                    CommandMetadataProviderInterface::class,
                    CommandMetadataProviderInterface::class,
                ));
            }
        }

        return new ResourceLockMiddleware(
            lockFactory: $lockFactory,
            metadata: $metadata,
        );
    }
}
