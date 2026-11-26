<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\CQRS\Lock\Attribute\Lock;

/** Static-analysis consumer fixture: callback parameters may name the concrete command. */
#[Lock(self::resourceKey(...))]
final readonly class TypedLockConsumer
{
    public function __construct(public int $id) {}

    public static function resourceKey(self $command): string
    {
        return 'typed:' . $command->id;
    }

    /** @return Lock<self> */
    public static function metadata(): Lock
    {
        return new Lock(static function (self $command): string {
            return self::resourceKey($command);
        });
    }
}
