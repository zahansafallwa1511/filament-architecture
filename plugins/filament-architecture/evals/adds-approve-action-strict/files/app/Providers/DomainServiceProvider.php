<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public array $bindings = [
        \App\Domain\Shared\Clock::class => \App\Infrastructure\Shared\LaravelClock::class,
    ];
}
