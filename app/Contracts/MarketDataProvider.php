<?php

namespace App\Contracts;

interface MarketDataProvider
{
    /** @return array<int, \App\Data\Candle> Oldest candle first. */
    public function candles(string $symbol, string $interval, int $limit = 300): array;

    public function supports(string $symbol): bool;

    public function name(): string;
}
