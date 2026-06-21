<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\ResourceLockMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Lock\ConfigProvider;

it('registers resource lock middleware factory', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        ResourceLockMiddleware::class => ResourceLockMiddlewareFactory::class,
    ]);
});
