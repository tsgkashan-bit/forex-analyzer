<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use App\Data\Candle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TwelveDataProvider implements MarketDataProvider
{
    public function candles(string $symbol, string $interval, int $limit = 300): array
    {
        $response = $this->client()->get('/time_series', [
            'symbol' => $this->normalizeSymbol($symbol),
            'interval' => $this->normalizeInterval($interval),
            'outputsize' => min(max($limit, 50), 5000),
            'order' => 'ASC',
            'timezone' => 'UTC',
            'apikey' => config('trading.providers.twelve_data.key'),
        ])->throw()->json();

        if (($response['status'] ?? null) === 'error' || empty($response['values'])) {
            throw new RuntimeException($response['message'] ?? 'No market candles returned.');
        }

        return array_map(
            static fn (array $row): Candle => Candle::fromArray($row),
            $response['values']
        );
    }

    public function supports(string $symbol): bool
    {
        return filled(config('trading.providers.twelve_data.key'));
    }

    public function name(): string
    {
        return 'Twelve Data';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.twelvedata.com')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 350, throw: false);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        $aliases = [
            'NAS100' => 'NDX',
            'US30' => 'DJI',
            'XAUUSD' => 'XAU/USD',
            'XAGUSD' => 'XAG/USD',
        ];

        if (isset($aliases[$symbol])) {
            return $aliases[$symbol];
        }

        if (preg_match('/^[A-Z]{6}$/', $symbol)) {
            return substr($symbol, 0, 3).'/'.substr($symbol, 3, 3);
        }

        if (str_ends_with($symbol, 'USDT')) {
            return substr($symbol, 0, -4).'/USDT';
        }

        return $symbol;
    }

    private function normalizeInterval(string $interval): string
    {
        return match (strtoupper(trim($interval))) {
            '1M' => '1min',
            '5M' => '5min',
            '15M' => '15min',
            '30M' => '30min',
            '1H' => '1h',
            '4H' => '4h',
            'D', '1D', 'DAILY' => '1day',
            default => throw new RuntimeException('Unsupported timeframe.'),
        };
    }
}
