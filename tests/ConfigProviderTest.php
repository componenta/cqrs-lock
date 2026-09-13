<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\DI\ContainerFactory;
use Componenta\CQRS\Command\Middleware\ResourceLockMiddleware;
use Componenta\CQRS\Lock\Attribute\Lock;
use Componenta\CQRS\Lock\ConfigProvider;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Lock('provider.contract', blocking: false)]
final class LockProviderCommand
{
}

it('uses discovered metadata to hold and release the configured lock', function (): void {
    $locks = new LockFactory(new InMemoryStore());
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new \Componenta\CQRS\ConfigProvider(),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => [LockFactory::class => $locks]]],
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $probe = $locks->createLock('provider.contract');
    $handler = new class ($probe) implements OperationHandlerInterface {
        public function __construct(private \Symfony\Component\Lock\SharedLockInterface $probe)
        {
        }
        public function handle(OperationInterface $operation): OperationInterface
        {
            return $operation->withResult(new OperationResult($this->probe->acquire(false)));
        }
    };
    $result = $container->get(ResourceLockMiddleware::class)->execute(Operation::create(new LockProviderCommand()), $handler);
    expect($result->result?->value)->toBeFalse()->and($probe->acquire(false))->toBeTrue();
    $probe->release();
});
