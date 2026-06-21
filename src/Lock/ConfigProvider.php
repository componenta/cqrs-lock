<?php

declare(strict_types=1);

namespace Componenta\CQRS\Lock;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ResourceLockMiddleware::class => \Componenta\CQRS\Command\Factory\ResourceLockMiddlewareFactory::class,
        ];
    }
}
