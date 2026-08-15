# Componenta CQRS Lock

Resource lock middleware package for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Lock\Attribute\Lock]`.

```bash
composer require componenta/cqrs-lock
```

Register the provider and configure `Symfony\Component\Lock\LockFactory` in the container.

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Lock\ConfigProvider(),
];
```

The provider registers `Componenta\CQRS\Lock\Attribute\Lock` as a generic CQRS metadata attribute and provides `Componenta\CQRS\Command\Middleware\ResourceLockMiddleware` plus lock-related exceptions. With `componenta/cqrs-app`, the attribute is included in the versioned build map automatically; without it, the core metadata provider can reflect an unknown command at runtime.

Add `ResourceLockMiddleware` to `ConfigKey::COMMAND_MIDDLEWARES` where locking is required. The application must provide `Symfony\Component\Lock\LockFactory`.

The `ttl` value is the maximum expected command duration. The middleware acquires and releases the lock, but does not refresh it while the handler runs; choose a TTL longer than the worst-case execution time. `blocking: true` waits according to the configured Symfony lock store and does not add a middleware-level deadline.

Placeholders such as `{accountId}` must refer to initialized, non-static stored properties without PHP property hooks. Hooked or virtual properties are rejected without invoking their accessors.
