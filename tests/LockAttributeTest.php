<?php

declare(strict_types=1);

use Componenta\CQRS\Lock\Attribute\Lock;

it('normalizes the lock template and exposes the documented defaults', function (): void {
    $lock = new Lock('  account:{id}  ');
    expect($lock->key)->toBe('account:{id}')
        ->and($lock->ttl)->toBe(300.0)
        ->and($lock->blocking)->toBeTrue();

    $custom = new Lock('account', ttl: 0.25, blocking: false);
    expect($custom->ttl)->toBe(0.25)->and($custom->blocking)->toBeFalse();
});

it('rejects empty and whitespace-only lock templates', function (string $key): void {
    expect(fn () => new Lock($key))->toThrow(InvalidArgumentException::class, 'empty or whitespace');
})->with(['', " \t\n"]);

it('rejects non-positive or non-finite lock lifetimes', function (float $ttl): void {
    expect(fn () => new Lock('account', ttl: $ttl))->toThrow(InvalidArgumentException::class, 'positive finite');
})->with([0.0, -1.0, INF, -INF, NAN]);
