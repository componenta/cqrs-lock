<?php

declare(strict_types=1);

namespace Componenta\CQRS\Lock;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Lock\Attribute\Lock;

final class ConfigProvider extends BaseConfigProvider
{
    /**
     * @return array<string, list<class-string>>
     */
    protected function getConfig(): array
    {
        return [
            ConfigKey::COMMAND_METADATA_ATTRIBUTES => [Lock::class],
        ];
    }

    protected function getFactories(): array
    {
        return [
            ResourceLockMiddleware::class => \Componenta\CQRS\Command\Factory\ResourceLockMiddlewareFactory::class,
        ];
    }
}
