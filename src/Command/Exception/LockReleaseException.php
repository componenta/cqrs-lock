<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use RuntimeException;
use Throwable;

final class LockReleaseException extends RuntimeException
{
    public function __construct(
        public readonly ?Throwable $primaryFailure,
        public readonly Throwable $releaseFailure,
    ) {
        parent::__construct(
            $primaryFailure === null
                ? sprintf('Lock release failed with "%s".', $releaseFailure->getMessage())
                : sprintf(
                    'Command failed with "%s" and lock release also failed with "%s".',
                    $primaryFailure->getMessage(),
                    $releaseFailure->getMessage(),
                ),
            previous: $primaryFailure ?? $releaseFailure,
        );
    }
}
