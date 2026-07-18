<?php

namespace App\Providers;

use App\Services\MarketData\BinanceProvider;
use App\Services\MarketData\MarketDataManager;
use App\Services\MarketData\TwelveDataProvider;
use Illuminate\Support\ServiceProvider;

final class TradingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            MarketDataManager::class,
            fn ($app) => new MarketDataManager([
                $app->make(BinanceProvider::class),
                $app->make(TwelveDataProvider::class),
            ])
        );
    }
}
