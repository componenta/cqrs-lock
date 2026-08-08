<?php

declare(strict_types=1);

namespace Componenta\CQRS\Lock\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Lock
{
    public string $key;

    public function __construct(
        string $key,
        public float $ttl = 300.0,
        public bool $blocking = true,
    ) {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('Lock key cannot be empty or whitespace');
        }

        if ($ttl <= 0.0 || !is_finite($ttl)) {
            throw new InvalidArgumentException('Lock TTL must be a positive finite number');
        }

        $this->key = $key;
    }
}
