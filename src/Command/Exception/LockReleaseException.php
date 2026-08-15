<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use RuntimeException;
use Throwable;

final class LockReleaseException extends RuntimeException
{
    public function __construct(
        public readonly Throwable $primaryFailure,
        public readonly Throwable $releaseFailure,
    ) {
        parent::__construct(
            sprintf(
                'Command failed with "%s" and lock release also failed with "%s".',
                $primaryFailure->getMessage(),
                $releaseFailure->getMessage(),
            ),
            previous: $primaryFailure,
        );
    }
}
