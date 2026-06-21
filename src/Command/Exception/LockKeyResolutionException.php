<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use InvalidArgumentException;
use Throwable;

final class LockKeyResolutionException extends InvalidArgumentException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}