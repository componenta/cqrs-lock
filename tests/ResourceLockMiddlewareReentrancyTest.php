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
        public function __construct(private readonly Lock $lock) {}

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Lock::class ? $this->lock : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
        }
    };
}

it('treats nested dispatch of the same resource as reentrant', function (): void {
    $middleware = new ResourceLockMiddleware(
        new LockFactory(new InMemoryStore()),
        reentrantLockTestMetadata(new Lock('resource', blocking: false)),
    );
    $inner = new class implements OperationHandlerInterface {
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
        ) {}

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
    $holdingHandler = new class implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            Fiber::suspend('locked');

            return $operation;
        }
    };
    $contender = new class implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            return $operation;
        }
    };
    $fiber = new Fiber(
        fn(): OperationInterface => $middleware->execute(
            Operation::create(new stdClass()),
            $holdingHandler,
        ),
    );

    expect($fiber->start())->toBe('locked')
        ->and(fn() => $middleware->execute(Operation::create(new stdClass()), $contender))
        ->toThrow(LockAcquisitionException::class);

    $fiber->resume();

    expect($fiber->isTerminated())->toBeTrue();
});
