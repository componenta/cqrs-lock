<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware\Internal;

use Fiber;
use LogicException;
use WeakMap;

/** @internal */
final class LockExecutionState
{
    /** @var array<string, positive-int> */
    private array $main = [];

    /** @var WeakMap<Fiber, array<string, positive-int>> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function contains(string $key): bool
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return isset($this->main[$key]);
        }

        return isset(($this->fibers[$fiber] ?? [])[$key]);
    }

    public function enter(string $key): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main[$key] = ($this->main[$key] ?? 0) + 1;

            return;
        }

        $locks = $this->fibers[$fiber] ?? [];
        $locks[$key] = ($locks[$key] ?? 0) + 1;
        $this->fibers[$fiber] = $locks;
    }

    public function leave(string $key): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            if (!isset($this->main[$key])) {
                throw new LogicException(sprintf(
                    'Lock execution state for "%s" is not held in the main context.',
                    $key,
                ));
            }

            if ($this->main[$key] === 1) {
                unset($this->main[$key]);
            } else {
                --$this->main[$key];
            }

            return;
        }

        $locks = $this->fibers[$fiber] ?? [];

        if (!isset($locks[$key])) {
            throw new LogicException(sprintf(
                'Lock execution state for "%s" is not held in the current fiber.',
                $key,
            ));
        }

        if ($locks[$key] === 1) {
            unset($locks[$key]);
        } else {
            --$locks[$key];
        }

        if ($locks === []) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $locks;
        }
    }
}
