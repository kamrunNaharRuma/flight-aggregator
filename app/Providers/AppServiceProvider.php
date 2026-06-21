<?php

namespace App\Providers;

use App\FlightProviders\Adapters\ProviderAAdapter;
use App\FlightProviders\Adapters\ProviderBAdapter;
use App\FlightProviders\Adapters\ProviderCAdapter;
use App\FlightProviders\ProviderManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class, function () {
            return new ProviderManager([
                new ProviderAAdapter(),
                new ProviderBAdapter(),
                new ProviderCAdapter(),
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
