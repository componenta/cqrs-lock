<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use RuntimeException;

final class LockAcquisitionException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Failed to acquire lock: {$key}");
    }
}