<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class MarketDataManager
{
    /** @param iterable<MarketDataProvider> $providers */
    public function __construct(private iterable $providers) {}

    /** @return array{provider:string,candles:array} */
    public function candles(string $symbol, string $interval, int $limit = 300): array
    {
        $errors = [];

        foreach ($this->providers as $provider) {
            if (!$provider->supports($symbol)) {
                continue;
            }

            try {
                $cacheKey = 'market-candles:'.sha1($provider->name().'|'.strtoupper($symbol).'|'.strtoupper($interval).'|'.$limit);
                $ttl = $this->cacheSeconds($interval);
                $candles = Cache::remember($cacheKey, now()->addSeconds($ttl), fn () => $provider->candles($symbol, $interval, $limit));

                return [
                    'provider' => $provider->name(),
                    'candles' => $candles,
                ];
            } catch (Throwable $e) {
                report($e);
                $errors[] = $provider->name().': '.$e->getMessage();
            }
        }

        throw new RuntimeException($errors ? implode(' | ', $errors) : 'No live market-data provider supports this symbol.');
    }

    private function cacheSeconds(string $interval): int
    {
        return match (strtoupper($interval)) {
            '1M' => 20,
            '5M' => 45,
            '15M' => 90,
            '30M' => 120,
            '1H' => 180,
            '4H' => 300,
            'D', '1D', 'DAILY' => 600,
            default => 30,
        };
    }
}
