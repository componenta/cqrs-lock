<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\PipelineHandler;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Lock\Attribute\Lock;
use Componenta\CQRS\Retry\Attribute\Retry;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

$package = dirname(__DIR__);
$loader = require $package . '/vendor/autoload.php';
$retrySource = $argv[1] ?? dirname($package) . '/cqrs-retry/src';
if (!is_file($retrySource . '/Retry/Attribute/Retry.php')) {
    throw new RuntimeException('Pass the current cqrs-retry source directory as the first argument.');
}
$loader->addPsr4('Componenta\\CQRS\\', $retrySource, true);
$loader->addPsr4('Componenta\\CQRS\\', $package . '/src', true);

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CountingStore extends InMemoryStore
{
    public int $acquisitions = 0;
    public int $releases = 0;

    public function save(Key $key): void
    {
        parent::save($key);
        ++$this->acquisitions;
    }

    public function delete(Key $key): void
    {
        parent::delete($key);
        ++$this->releases;
    }
}

#[Lock(static function (TopologyCommand $command): string {
    ++$command->keyCalls;

    return 'topology-resource';
}, blocking: false)]
#[Retry(attempts: 3, delayMs: 0, when: static function (Throwable $failure, OperationInterface $operation, int $failedAttempt): bool {
    $operation->command->decisions[] = $failedAttempt;

    return $failure instanceof RuntimeException;
})]
final class TopologyCommand
{
    public int $keyCalls = 0;
    public array $decisions = [];
}

$results = [];
foreach (['lock-then-retry', 'retry-then-lock'] as $order) {
    $store = new CountingStore();
    $factory = new LockFactory($store);
    $metadata = new ReflectionCommandMetadataProvider();
    $lock = new ResourceLockMiddleware($factory, $metadata);
    $retry = new RetryMiddleware($metadata);
    $command = new TopologyCommand();
    $operation = Operation::create($command);
    $success = $operation->withResult(new OperationResult('done'));
    $handler = new class ($factory, $operation, $success) implements OperationHandlerInterface {
        public int $attempts = 0;

        public function __construct(
            private LockFactory $factory,
            private OperationInterface $original,
            private OperationInterface $success,
        ) {}

        public function handle(OperationInterface $operation): OperationInterface
        {
            verify($operation === $this->original, 'Every attempt must receive the original operation.');
            $contender = $this->factory->createLock('topology-resource');
            verify(!$contender->acquire(false), 'The real resource must stay locked during each handler attempt.');
            if (++$this->attempts < 3) {
                throw new RuntimeException('transient');
            }

            return $this->success;
        }
    };
    $pipeline = PipelineHandler::compile($handler, $order === 'lock-then-retry' ? [$lock, $retry] : [$retry, $lock]);
    verify($pipeline->handle($operation) === $success, 'The exact successful handler result must be returned.');
    $expectedAcquisitions = $order === 'lock-then-retry' ? 1 : 3;
    verify($handler->attempts === 3, 'There must be three downstream attempts.');
    verify($command->decisions === [1, 2], 'Only failures with another attempt may be classified.');
    verify($command->keyCalls === $expectedAcquisitions, 'The key must be resolved once per lock middleware entry.');
    verify($store->acquisitions === $expectedAcquisitions, 'Acquisition count must follow pipeline order.');
    verify($store->releases === $expectedAcquisitions, 'Every acquisition must be released.');
    $results[$order] = [
        'attempts' => $handler->attempts,
        'keyCalls' => $command->keyCalls,
        'acquisitions' => $store->acquisitions,
        'releases' => $store->releases,
        'decisions' => $command->decisions,
    ];
    $contender = $factory->createLock('topology-resource');
    verify($contender->acquire(false), 'The resource must be available after the whole pipeline.');
    $contender->release();
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
