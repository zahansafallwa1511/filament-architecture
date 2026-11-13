<?php

namespace App\Infrastructure\Shared;

use App\Domain\Shared\Clock;

final class LaravelClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
