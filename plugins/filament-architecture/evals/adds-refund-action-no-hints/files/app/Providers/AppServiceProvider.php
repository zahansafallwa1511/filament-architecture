<?php

namespace App\Providers;

use App\Services\StripeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret')));
    }
}
