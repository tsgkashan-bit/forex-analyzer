<?php

namespace App\Data;

final readonly class Candle
{
    public function __construct(
        public string $time,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public ?float $volume = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            time: (string) ($row['datetime'] ?? $row['time'] ?? ''),
            open: (float) $row['open'],
            high: (float) $row['high'],
            low: (float) $row['low'],
            close: (float) $row['close'],
            volume: isset($row['volume']) ? (float) $row['volume'] : null,
        );
    }
}
