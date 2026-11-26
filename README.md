# Componenta CQRS Lock

Resource locking for CQRS commands using string templates or native PHP callbacks in `#[Lock]`.

[Русская документация](README.ru.md)

## Requirements and registration

The v4 line requires PHP **8.5.13+**, Componenta CQRS **5**, Componenta Config **4**, and Symfony Lock **8.1+** within the 8.x line.

```bash
composer require componenta/cqrs-lock
```

Register the providers, configure `Symfony\Component\Lock\LockFactory` in the container, and add `Componenta\CQRS\Command\Middleware\ResourceLockMiddleware` to the command middleware chain:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Lock\ConfigProvider(),
];
```

The factory uses the configured `LockFactory` and CQRS `CommandMetadataProviderInterface`. The default metadata provider caches reflection descriptors and creates a new attribute on every lookup. Callback bodies run during dispatch, after metadata lookup, before lock acquisition.

## Template keys

```php
use Componenta\CQRS\Lock\Attribute\Lock;

#[Lock('account:{accountId}', ttl: 30.0, blocking: false)]
final readonly class RebuildAccount
{
    public function __construct(public int $accountId) {}
}
```

String keys retain the existing template behavior. Outer whitespace is trimmed when constructing the attribute. Placeholders refer to initialized, non-static stored properties without PHP property hooks. Null becomes `"null"`, booleans become `"true"` or `"false"`, and scalar or `Stringable` values become strings. Missing, static, uninitialized, hooked, and unsupported properties fail key resolution.

## Callback keys

Use a callback when a resource key requires several values or application-specific normalization:

```php
use Componenta\CQRS\Lock\Attribute\Lock;

#[Lock(self::resourceKey(...), ttl: 30.0, blocking: false)]
final readonly class RebuildProduct
{
    public function __construct(
        public int $tenantId,
        public int $productId,
    ) {}

    private static function resourceKey(self $command): string
    {
        return 'product:' . $command->tenantId . ':' . $command->productId;
    }
}
```

The callback receives the exact command object and must return a nonblank string. Each middleware entry evaluates it once, including a reentrant entry. The returned string is used exactly: whitespace is preserved, `"0"` is valid, and braces are literal. The middleware does not interpolate, hash, prefix, or memoize callback results. It keeps the resolved key for that entry even if the handler later changes the command.

Inline declarations can use a capture-free static closure:

```php
#[Lock(static function (RebuildProduct $command): string {
    return 'product:' . $command->tenantId . ':' . $command->productId;
})]
final readonly class RebuildProduct
{
    public function __construct(public int $tenantId, public int $productId) {}
}
```

PHP 8.5 attribute arguments support `static function` and first-class function/static-method callables. Arrow functions (`fn` and `static fn`) are not supported in these constant expressions. PHP 8.5.13 is the minimum because it fixes trait first-class-callable scope and visibility. Native private method scope is preserved; callbacks are not reconstructed from method names.

The public key type is `string|Closure`. A string always means a template, including a string that names a PHP function. Programmatic metadata can supply a `Closure`; arbitrary callable arrays and invokable objects must be converted explicitly. A callback needs no container registration or parameter autowiring.

## Execution and failures

| Setting | Default | Meaning |
|---|---|---|
| `key` | Required | String template or `Closure(object): string`. |
| `ttl` | `300.0` | Positive finite duration in seconds, passed to Symfony Lock. |
| `blocking` | `true` | Symfony store acquisition mode. |

A callback exception, native type error, invalid result type, or blank result raises `Componenta\CQRS\Command\Exception\LockKeyResolutionException` before acquisition and handler execution. A callback throwable is retained as `previous`. Error text identifies the command class and required result contract without copying the key or payload. Attribute-materialization errors remain metadata-provider errors.

The same resolved key is reentrant in one execution context. Different Fibers must acquire their own locks. Locks are released on handler success or failure. `LockReleaseException` reports a release failure; its `primaryFailure` and `releaseFailure` retain both failures when command execution also failed.

The callback can mutate shared application objects like any PHP code. Reassigning its command argument does not replace the operation's command. Applications own key collisions, tenant/resource namespaces, and stable callback computations.

## Middleware order

The application controls middleware order. Placing policy before the lock avoids acquisition for commands that policy rejects. A policy inside the lock runs within the locked section.

`ResourceLockMiddleware → RetryMiddleware` holds one lock across the retry attempts. `RetryMiddleware → ResourceLockMiddleware` resolves and acquires the key on each attempt. The callback does not add another lock or retry layer.

The middleware does not refresh a lease during execution. Choose a TTL suitable for everything inside the locked section; a remote lease can expire before the handler finishes. Blocking follows the configured Symfony store and adds no middleware-level deadline.

## Development

Run `composer test` and `composer analyse`. The [implementation specification](docs/specifications/attribute-callbacks.md) describes the callback contract and acceptance cases; [integration verification](docs/development/integration.md) records dependency revisions and reproducible development setup.
