<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\LockAcquisitionException;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Lock\Attribute\Lock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

function reentrantLockTestMetadata(Lock $lock): CommandMetadataProviderInterface
{
    return new class ($lock) implements CommandMetadataProviderInterface {
        public function __construct(private readonly Lock $lock)
        {
        }

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Lock::class ? $this->lock : null;
        }
    };
}

it('treats nested dispatch of the same resource as reentrant', function (): void {
    $middleware = new ResourceLockMiddleware(
        new LockFactory(new InMemoryStore()),
        reentrantLockTestMetadata(new Lock('resource', blocking: false)),
    );
    $inner = new class () implements OperationHandlerInterface {
        public int $calls = 0;

        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;

            return $operation;
        }
    };
    $outer = new class ($middleware, $inner) implements OperationHandlerInterface {
        public int $calls = 0;

        public function __construct(
            private readonly ResourceLockMiddleware $middleware,
            private readonly OperationHandlerInterface $inner,
        ) {
        }

        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;
            $this->middleware->execute(Operation::create(new stdClass()), $this->inner);

            return $operation;
        }
    };

    $operation = Operation::create(new stdClass());

    expect($middleware->execute($operation, $outer))->toBe($operation)
        ->and($outer->calls)->toBe(1)
        ->and($inner->calls)->toBe(1);
});

it('keeps lock ownership isolated between fibers', function (): void {
    $middleware = new ResourceLockMiddleware(
        new LockFactory(new InMemoryStore()),
        reentrantLockTestMetadata(new Lock('resource', blocking: false)),
    );
    $holdingHandler = new class () implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            Fiber::suspend('locked');

            return $operation;
        }
    };
    $contender = new class () implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            return $operation;
        }
    };
    $fiber = new Fiber(
        fn (): OperationInterface => $middleware->execute(
            Operation::create(new stdClass()),
            $holdingHandler,
        ),
    );

    expect($fiber->start())->toBe('locked')
        ->and(fn () => $middleware->execute(Operation::create(new stdClass()), $contender))
        ->toThrow(LockAcquisitionException::class);

    $fiber->resume();

    expect($fiber->isTerminated())->toBeTrue();
});

it('holds the real lock throughout nested execution and releases it on success or failure', function (bool $fail): void {
    $factory = new LockFactory(new InMemoryStore());
    $middleware = new ResourceLockMiddleware(
        $factory,
        reentrantLockTestMetadata(new Lock('account:{id}', blocking: false)),
    );
    $command = new readonly class {
        public int $id;
        public function __construct() { $this->id = 42; }
    };
    $operation = Operation::create($command);
    $probe = static function () use ($factory): bool {
        $lock = $factory->createLock('account:42');
        $acquired = $lock->acquire(false);
        if ($acquired) {
            $lock->release();
        }

        return $acquired;
    };
    $failure = new DomainException('nested failure');
    $inner = new readonly class ($probe, $failure, $fail) implements OperationHandlerInterface {
        public function __construct(
            private Closure $probe,
            private Throwable $failure,
            private bool $fail,
        ) {}
        public function handle(OperationInterface $operation): OperationInterface
        {
            expect(($this->probe)())->toBeFalse();
            if ($this->fail) {
                throw $this->failure;
            }
            return $operation;
        }
    };
    $outer = new readonly class ($middleware, $inner, $probe) implements OperationHandlerInterface {
        public function __construct(
            private ResourceLockMiddleware $middleware,
            private OperationHandlerInterface $inner,
            private Closure $probe,
        ) {}
        public function handle(OperationInterface $operation): OperationInterface
        {
            expect(($this->probe)())->toBeFalse();
            try {
                return $this->middleware->execute($operation, $this->inner);
            } finally {
                expect(($this->probe)())->toBeFalse();
            }
        }
    };

    if ($fail) {
        try {
            $middleware->execute($operation, $outer);
            test()->fail('Expected nested failure.');
        } catch (DomainException $caught) {
            expect($caught)->toBe($failure);
        }
    } else {
        expect($middleware->execute($operation, $outer))->toBe($operation);
    }

    expect($probe())->toBeTrue();
    // A later call must acquire the lock, not inherit a stale reentrancy marker.
    $contender = $factory->createLock('account:42');
    expect($contender->acquire(false))->toBeTrue();
    try {
        expect(fn () => $middleware->execute($operation, $inner))
            ->toThrow(LockAcquisitionException::class);
    } finally {
        $contender->release();
    }
})->with([false, true]);
