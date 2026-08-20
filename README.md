# Componenta CQRS Lock

Resource lock middleware for CQRS v4 commands marked with `#[Componenta\CQRS\Lock\Attribute\Lock]`.

```bash
composer require componenta/cqrs-lock
```

`main` is the lock v3 line and requires `componenta/cqrs` v4.

Register the providers and configure `Symfony\Component\Lock\LockFactory` in the container:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Lock\ConfigProvider(),
];
```

The lock provider registers `Componenta\CQRS\Lock\Attribute\Lock` in `ConfigKey::COMMAND_METADATA_ATTRIBUTES` and provides `ResourceLockMiddleware`. With `componenta/cqrs-app`, lock metadata is discovered in development and compiled into the same versioned CQRS map used in production.

CQRS v4's standard `CommandMetadataProviderInterface` is strictly map-backed. There is no implicit reflection fallback for metadata absent from the active map. Applications that deliberately want reflection metadata must bind `ReflectionCommandMetadataProvider` explicitly.

Add `ResourceLockMiddleware` to `ConfigKey::COMMAND_MIDDLEWARES` where locking is required. Middleware ordering is application configuration. Placing policy before the lock avoids acquiring a distributed lock for a command that authorization later rejects; placing the lock outside policy deliberately makes authorization part of the locked section.

Lock/retry ordering is also application-defined. `ResourceLockMiddleware -> RetryMiddleware` holds one lock across all retry attempts. `RetryMiddleware -> ResourceLockMiddleware` acquires and releases the lock for each attempt. Choose the topology that matches the resource and retry semantics.

The `ttl` value is the maximum expected uninterrupted command duration while the lock is held. The middleware acquires and releases the lock, but does not refresh it while downstream execution runs; choose a TTL long enough for everything placed inside the lock. `blocking: true` waits according to the configured Symfony lock store and does not add a middleware-level deadline.

Placeholders such as `{accountId}` must refer to initialized, non-static stored properties without PHP property hooks. Hooked or virtual properties are rejected without invoking their accessors.

A lock release failure is always reported as `LockReleaseException`, including the case where command execution itself succeeded. If both command execution and release fail, the exception preserves both failures.
