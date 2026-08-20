<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Exception\LockAcquisitionException;
use Componenta\CQRS\Command\Exception\LockKeyResolutionException;
use Componenta\CQRS\Command\Exception\LockReleaseException;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Lock\Attribute\Lock;
use ReflectionObject;
use Stringable;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/** Prevents concurrent execution of commands over the same resource. */
final readonly class ResourceLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LockFactory $lockFactory,
        private CommandMetadataProviderInterface $metadata,
    ) {
    }

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        $command = $operation->command;
        $lockAttr = $this->metadata->get($command, Lock::class);

        if ($lockAttr === null) {
            return $handler->handle($operation);
        }

        $reflection = new ReflectionObject($command);
        $key = $this->resolveKey($lockAttr->key, $command, $reflection);
        $lock = $this->lockFactory->createLock($key, $lockAttr->ttl, autoRelease: false);

        if (!$lock->acquire($lockAttr->blocking)) {
            throw new LockAcquisitionException($key);
        }

        $exception = null;

        try {
            return $handler->handle($operation);
        } catch (Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $releaseException) {
                throw new LockReleaseException($exception, $releaseException);
            }
        }
    }

    private function resolveKey(string $template, object $command, ReflectionObject $reflection): string
    {
        $result = preg_replace_callback(
            '/\{(\w+)}/',
            fn(array $m): string => $this->resolveProperty($m, $command, $reflection),
            $template,
        );

        if ($result === null || $result === '') {
            throw new LockKeyResolutionException(
                'Lock key resolved to empty string for ' . $command::class,
            );
        }

        return $result;
    }

    /** @param array<int, string> $match */
    private function resolveProperty(array $match, object $command, ReflectionObject $reflection): string
    {
        $name = $match[1];

        if (!$reflection->hasProperty($name)) {
            throw new LockKeyResolutionException(
                "Property '$name' does not exist on " . $command::class,
            );
        }

        $property = $reflection->getProperty($name);

        if ($property->isStatic()) {
            throw new LockKeyResolutionException(
                "Property '$name' on " . $command::class . ' must not be static',
            );
        }

        if ($property->isVirtual() || $property->getHooks() !== []) {
            throw new LockKeyResolutionException(
                "Property '$name' on " . $command::class . ' must be a stored property without hooks',
            );
        }

        if (!$property->isInitialized($command)) {
            throw new LockKeyResolutionException(
                "Property '$name' is not initialized on " . $command::class,
            );
        }

        try {
            $value = $property->getValue($command);
        } catch (Throwable $exception) {
            throw new LockKeyResolutionException(
                "Cannot read property '$name' on " . $command::class . ": {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof Stringable => $this->stringableToString($value, $name, $command::class),
            default => throw new LockKeyResolutionException(
                "Property '{$name}' on " . $command::class . ' is not convertible to string',
            ),
        };
    }

    private function stringableToString(Stringable $value, string $property, string $class): string
    {
        try {
            return $value->__toString();
        } catch (Throwable $e) {
            throw new LockKeyResolutionException(
                "Property '$property' on $class threw exception during string conversion: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
