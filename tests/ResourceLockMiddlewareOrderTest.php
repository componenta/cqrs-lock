<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Middleware\MiddlewareOrder;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;

it('declares resource locking after the optional policy boundary', function (): void {
    $attributes = (new ReflectionClass(ResourceLockMiddleware::class))
        ->getAttributes(MiddlewareOrder::class);

    expect($attributes)->toHaveCount(1);

    /** @var MiddlewareOrder $order */
    $order = $attributes[0]->newInstance();

    expect($order->after)->toBe([
        Componenta\CQRS\Command\Middleware\PolicyMiddleware::class,
    ])->and($order->before)->toBe([]);
});
