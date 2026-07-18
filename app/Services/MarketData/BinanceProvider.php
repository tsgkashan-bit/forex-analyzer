<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use App\Data\Candle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BinanceProvider implements MarketDataProvider
{
    public function candles(string $symbol, string $interval, int $limit = 300): array
    {
        $response = $this->client()->get('/api/v3/klines', [
            'symbol' => $this->normalizeSymbol($symbol),
            'interval' => $this->normalizeInterval($interval),
            'limit' => min(max($limit, 50), 1000),
        ]);

        if ($response->failed()) {
            throw new RuntimeException(data_get($response->json(), 'msg', 'Binance market-data request failed.'));
        }

        $rows = $response->json();
        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('Binance returned no market candles.');
        }

        return array_map(static function (array $row): Candle {
            return new Candle(
                time: gmdate('Y-m-d H:i:s', (int) floor(((int) $row[0]) / 1000)),
                open: (float) $row[1],
                high: (float) $row[2],
                low: (float) $row[3],
                close: (float) $row[4],
                volume: (float) $row[5],
            );
        }, $rows);
    }

    public function supports(string $symbol): bool
    {
        $symbol = strtoupper(str_replace(['/', '-', '_'], '', trim($symbol)));
        return (bool) preg_match('/^[A-Z0-9]{2,12}USDT$/', $symbol);
    }

    public function name(): string
    {
        return 'Binance Public Market Data';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.binance.com')
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '-', '_'], '', trim($symbol)));
    }

    private function normalizeInterval(string $interval): string
    {
        return match (strtoupper(trim($interval))) {
            '1M' => '1m',
            '5M' => '5m',
            '15M' => '15m',
            '30M' => '30m',
            '1H' => '1h',
            '4H' => '4h',
            'D', '1D', 'DAILY' => '1d',
            default => throw new RuntimeException('Unsupported Binance timeframe.'),
        };
    }
}
