<?php

declare(strict_types=1);

namespace Componenta\CQRS\Lock\Attribute;

use Attribute;
use Closure;
use InvalidArgumentException;

/** @template TCommand of object */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Lock
{
    /** @var string|(Closure(TCommand): string) */
    public string|Closure $key;

    /** @param string|(Closure(TCommand): string) $key */
    public function __construct(
        string|Closure $key,
        public float $ttl = 300.0,
        public bool $blocking = true,
    ) {
        if (is_string($key)) {
            $key = trim($key);

            if ($key === '') {
                throw new InvalidArgumentException('Lock key cannot be empty or whitespace');
            }
        }

        if ($ttl <= 0.0 || !is_finite($ttl)) {
            throw new InvalidArgumentException('Lock TTL must be a positive finite number');
        }

        $this->key = $key;
    }
}
