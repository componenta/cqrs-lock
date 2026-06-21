# Componenta CQRS Lock

Resource lock middleware package for `componenta/cqrs` commands marked with `#[Lock]`.

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

The package provides `Componenta\CQRS\Command\Middleware\ResourceLockMiddleware` and lock-related exceptions.