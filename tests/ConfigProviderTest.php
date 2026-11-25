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


it('registers its factory and rejects invalid lock and metadata services', function (): void {
    $factoryClass = \Componenta\CQRS\Command\Factory\ResourceLockMiddlewareFactory::class;
    $config = (new ConfigProvider())();
    expect($config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][ResourceLockMiddleware::class])->toBe($factoryClass);

    $container = $this->createMock(\Psr\Container\ContainerInterface::class);
    $container->expects($this->once())->method('get')->with(LockFactory::class)->willReturn(new stdClass());
    expect(fn () => (new $factoryClass())($container))->toThrow(LogicException::class, LockFactory::class);

    $container = $this->createStub(\Psr\Container\ContainerInterface::class);
    $container->method('get')->willReturnMap([
        [LockFactory::class, new LockFactory(new InMemoryStore())],
        [\Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface::class, new stdClass()],
    ]);
    expect(fn () => (new $factoryClass())($container))
        ->toThrow(LogicException::class, \Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface::class);
});
