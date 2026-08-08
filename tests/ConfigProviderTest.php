<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\ResourceLockMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Lock\Attribute\Lock;
use Componenta\CQRS\Lock\ConfigProvider;

it('registers its middleware and command metadata attribute', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        ResourceLockMiddleware::class => ResourceLockMiddlewareFactory::class,
    ])->and($config[ConfigKey::COMMAND_METADATA_ATTRIBUTES])->toBe([
        Lock::class,
    ]);
});
