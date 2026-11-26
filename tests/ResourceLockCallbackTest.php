<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\LockCallbacks;

use Closure;
use Componenta\CQRS\Command\Exception\LockAcquisitionException;
use Componenta\CQRS\Command\Exception\LockKeyResolutionException;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Lock\Attribute\Lock;
use Fiber;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use TypeError;

#[Lock(static function (InlineKeyCommand $command): string {
    ++$command->resolutions;

    return $command->key;
}, blocking: false)]
final class InlineKeyCommand
{
    public int $resolutions = 0;

    public function __construct(public string $key) {}
}

#[Lock('shared-resource', blocking: false)]
final readonly class TemplateCommand {}

trait ScopedKey
{
    private const Closure KEY = self::resolveKey(...);

    private static function resolveKey(self $command): string
    {
        ++$command->resolutions;

        return self::PREFIX . $command->id;
    }
}

#[Lock(self::KEY, blocking: false)]
final class FirstScopedCommand
{
    use ScopedKey;

    private const string PREFIX = 'first:';
    public int $resolutions = 0;

    public function __construct(public int $id) {}
}

#[Lock(self::KEY, blocking: false)]
final class SecondScopedCommand
{
    use ScopedKey;

    private const string PREFIX = 'second:';
    public int $resolutions = 0;

    public function __construct(public int $id) {}
}

final class RecordingStore implements PersistingStoreInterface
{
    public array $saved = [];
    public array $deleted = [];
    private InMemoryStore $store;

    public function __construct()
    {
        $this->store = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->saved[] = (string) $key;
        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->deleted[] = (string) $key;
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}

function metadata(Lock $lock): CommandMetadataProviderInterface
{
    return new readonly class ($lock) implements CommandMetadataProviderInterface {
        public function __construct(private Lock $lock) {}

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Lock::class ? $this->lock : null;
        }
    };
}

function handler(Closure $callback): OperationHandlerInterface
{
    return new readonly class ($callback) implements OperationHandlerInterface {
        public function __construct(private Closure $callback) {}

        public function handle(OperationInterface $operation): OperationInterface
        {
            return ($this->callback)($operation);
        }
    };
}

it('materializes native callbacks without executing them and resolves each current command once', function (): void {
    $provider = new ReflectionCommandMetadataProvider();
    $first = new InlineKeyCommand('tenant:1');
    $second = new InlineKeyCommand('tenant:2');
    $attribute = $provider->get($first, Lock::class);
    $again = $provider->get($first, Lock::class);
    expect($attribute->key)->toBeInstanceOf(Closure::class)
        ->and($again)->not->toBe($attribute)
        ->and($first->resolutions)->toBe(0);

    $store = new RecordingStore();
    $middleware = new ResourceLockMiddleware(new LockFactory($store), $provider);
    $next = handler(static fn (OperationInterface $operation): OperationInterface => $operation);
    foreach ([$first, $second] as $command) {
        $operation = Operation::create($command);
        expect($middleware->execute($operation, $next))->toBe($operation);
    }
    $first->key = 'tenant:3';
    $middleware->execute(Operation::create($first), $next);

    expect($store->saved)->toBe(['tenant:1', 'tenant:2', 'tenant:3'])
        ->and($store->deleted)->toBe($store->saved)
        ->and($first->resolutions)->toBe(2)
        ->and($second->resolutions)->toBe(1);
});

it('preserves private static callable scope for two consumers of the same trait on repeated metadata lookups', function (): void {
    $provider = new ReflectionCommandMetadataProvider();
    $store = new RecordingStore();
    $middleware = new ResourceLockMiddleware(new LockFactory($store), $provider);
    $next = handler(static fn (OperationInterface $operation): OperationInterface => $operation);
    $commands = [new FirstScopedCommand(1), new SecondScopedCommand(2)];
    foreach ([$commands[0], $commands[1], $commands[1], $commands[0]] as $command) {
        $provider->get($command, Lock::class);
        $middleware->execute(Operation::create($command), $next);
    }

    expect($store->saved)->toBe(['first:1', 'second:2', 'second:2', 'first:1'])
        ->and($store->deleted)->toBe($store->saved)
        ->and($commands[0]->resolutions)->toBe(2)
        ->and($commands[1]->resolutions)->toBe(2);
});

it('keeps the callback result opaque and releases the captured resource after command mutation', function (string $key): void {
    $store = new RecordingStore();
    $factory = new LockFactory($store);
    $command = new InlineKeyCommand($key);
    $operation = Operation::create($command);
    $middleware = new ResourceLockMiddleware($factory, new ReflectionCommandMetadataProvider());

    $result = $middleware->execute($operation, handler(static function (OperationInterface $input) use ($factory, $command, $key, $operation): OperationInterface {
        expect($input)->toBe($operation)->and($input->command)->toBe($command);
        $contender = $factory->createLock($key);
        expect($contender->acquire(false))->toBeFalse();
        $command->key = 'changed-during-handler';

        return $input;
    }));

    expect($result)->toBe($operation)
        ->and($store->deleted)->toBe([$key])
        ->and($command->resolutions)->toBe(1);
    $contender = $factory->createLock($key);
    expect($contender->acquire(false))->toBeTrue();
    $contender->release();
})->with(['0', '  resource:{literal}  ', "resource\tvalue"]);

it('rejects invalid callback keys before touching the lock store or handler', function (mixed $result): void {
    $store = new RecordingStore();
    $middleware = new ResourceLockMiddleware(
        new LockFactory($store),
        metadata(new Lock(static fn () => $result)),
    );
    $next = $this->createMock(OperationHandlerInterface::class);
    $next->expects($this->never())->method('handle');
    $command = (object) ['secret' => 'do-not-log-payload'];

    try {
        $middleware->execute(Operation::create($command), $next);
        test()->fail('Expected an invalid callback key to be rejected.');
    } catch (LockKeyResolutionException $failure) {
        expect($failure->getMessage())->toContain(stdClass::class, 'non-blank string')
            ->not->toContain('do-not-log-payload');
    }

    expect($store->saved)->toBe([])->and($store->deleted)->toBe([]);
})->with([
    'empty' => [''],
    'blank' => [" \t\n"],
    'integer' => [0],
    'boolean' => [true],
    'null' => [null],
    'array' => [[]],
    'Stringable' => [new class implements Stringable {
        public function __toString(): string { return 'resource'; }
    }],
]);

it('preserves callback failures even when the callback reassigns its command argument', function (): void {
    $primary = new RuntimeException('callback-sensitive-text');
    $store = new RecordingStore();
    $command = new InlineKeyCommand('resource');
    $middleware = new ResourceLockMiddleware(new LockFactory($store), metadata(new Lock(
        static function (&$input) use ($command, $primary): never {
            expect($input)->toBe($command);
            $input = null;
            throw $primary;
        },
    )));
    $next = $this->createMock(OperationHandlerInterface::class);
    $next->expects($this->never())->method('handle');

    try {
        $middleware->execute(Operation::create($command), $next);
        test()->fail('Expected the key callback to fail.');
    } catch (LockKeyResolutionException $failure) {
        expect($failure->getPrevious())->toBe($primary)
            ->and($failure->getMessage())->toContain(InlineKeyCommand::class)
            ->not->toContain('callback-sensitive-text');
    }
    expect($store->saved)->toBe([])->and($store->deleted)->toBe([]);
});

it('wraps native callback signature errors as key resolution failures', function (): void {
    $store = new RecordingStore();
    $middleware = new ResourceLockMiddleware(
        new LockFactory($store),
        metadata(new Lock(static function (int $command): string { return 'resource'; })),
    );
    $next = $this->createMock(OperationHandlerInterface::class);
    $next->expects($this->never())->method('handle');
    try {
        $middleware->execute(Operation::create(new stdClass()), $next);
        test()->fail('Expected the callback signature to fail.');
    } catch (LockKeyResolutionException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(TypeError::class);
    }
    expect($store->saved)->toBe([])->and($store->deleted)->toBe([]);
});

it('retains reentrancy and Fiber isolation for freshly resolved callback keys', function (): void {
    $factory = new LockFactory(new InMemoryStore());
    $middleware = new ResourceLockMiddleware($factory, new ReflectionCommandMetadataProvider());
    $command = new InlineKeyCommand('shared-resource');
    $operation = Operation::create($command);
    $terminal = handler(static fn (OperationInterface $input): OperationInterface => $input);
    $holding = handler(static function (OperationInterface $input) use ($middleware, $terminal): OperationInterface {
        expect($middleware->execute($input, $terminal))->toBe($input);
        Fiber::suspend('held');

        return $input;
    });
    $fiber = new Fiber(static fn (): OperationInterface => $middleware->execute($operation, $holding));
    expect($fiber->start())->toBe('held')
        ->and($command->resolutions)->toBe(2)
        ->and(fn () => $middleware->execute($operation, $terminal))->toThrow(LockAcquisitionException::class)
        ->and($command->resolutions)->toBe(3);
    $fiber->resume();

    expect($fiber->getReturn())->toBe($operation)
        ->and($middleware->execute($operation, $terminal))->toBe($operation)
        ->and($command->resolutions)->toBe(4);
});

it('shares callback and template ownership and cleans up after a nested failure', function (): void {
    $store = new RecordingStore();
    $middleware = new ResourceLockMiddleware(new LockFactory($store), new ReflectionCommandMetadataProvider());
    $operation = Operation::create(new InlineKeyCommand('shared-resource'));
    $failure = new RuntimeException('nested handler failed');
    $inner = handler(static fn (): never => throw $failure);
    $outer = handler(static fn (): OperationInterface => $middleware->execute(Operation::create(new TemplateCommand()), $inner));

    try {
        $middleware->execute($operation, $outer);
        test()->fail('Expected the nested handler failure.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($store->saved)->toBe(['shared-resource'])
        ->and($store->deleted)->toBe(['shared-resource']);
    $next = handler(static fn (OperationInterface $input): OperationInterface => $input);
    expect($middleware->execute($operation, $next))->toBe($operation)
        ->and($store->saved)->toBe(['shared-resource', 'shared-resource'])
        ->and($store->deleted)->toBe($store->saved);
});
