<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\LockKeyResolutionException;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Lock\Attribute\Lock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Lock('hook:{value}')]
final class LockHookedPropertyCommand
{
    public static int $reads = 0;

    public string $value {
        get {
            ++self::$reads;

            return 'computed';
        }
    }
}

it('rejects hooked lock-key properties without invoking their getters', function (): void {
    LockHookedPropertyCommand::$reads = 0;
    $middleware = new ResourceLockMiddleware(
        new LockFactory(new InMemoryStore()),
    );
    $handler = new class implements OperationHandlerInterface
    {
        public bool $called = false;

        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->called = true;

            return $operation;
        }
    };

    try {
        $middleware->execute(
            Operation::create(new LockHookedPropertyCommand()),
            $handler,
        );

        test()->fail('Expected lock-key resolution to reject a hooked property.');
    } catch (LockKeyResolutionException $exception) {
        expect($exception->getMessage())->toContain('stored property without hooks');
    }

    expect(LockHookedPropertyCommand::$reads)->toBe(0)
        ->and($handler->called)->toBeFalse();

});

it('preserves handler and lock release failures', function (): void {
    $primary = new RuntimeException('handler failed');
    $release = new RuntimeException('release failed');
    $store = new class ($release) implements Symfony\Component\Lock\PersistingStoreInterface {
        public function __construct(private readonly Throwable $releaseFailure)
        {
        }

        public function save(Symfony\Component\Lock\Key $key): void
        {
        }

        public function delete(Symfony\Component\Lock\Key $key): void
        {
            throw $this->releaseFailure;
        }

        public function exists(Symfony\Component\Lock\Key $key): bool
        {
            return true;
        }

        public function putOffExpiration(Symfony\Component\Lock\Key $key, float $ttl): void
        {
        }
    };
    $metadata = new class implements Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface {
        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Lock::class ? new Lock('resource') : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
        }
    };
    $middleware = new ResourceLockMiddleware(new LockFactory($store), $metadata);
    $handler = new class ($primary) implements OperationHandlerInterface {
        public function __construct(private readonly Throwable $failure)
        {
        }

        public function handle(OperationInterface $operation): OperationInterface
        {
            throw $this->failure;
        }
    };

    try {
        $middleware->execute(Operation::create(new stdClass()), $handler);
        test()->fail('Expected combined lock failure.');
    } catch (Componenta\CQRS\Command\Exception\LockReleaseException $exception) {
        expect($exception->primaryFailure)->toBe($primary)
            ->and($exception->releaseFailure->getPrevious())->toBe($release)
            ->and($exception->getPrevious())->toBe($primary);
    }
});
