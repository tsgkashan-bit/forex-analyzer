<?php

namespace App\Services\Analysis;

use App\Data\Candle;

final class IndicatorCalculator
{
    public function calculate(array $candles): array
    {
        $closes = array_map(fn (Candle $c) => $c->close, $candles);
        $highs = array_map(fn (Candle $c) => $c->high, $candles);
        $lows = array_map(fn (Candle $c) => $c->low, $candles);
        $volumes = array_values(array_filter(array_map(fn (Candle $c) => $c->volume, $candles), fn ($v) => $v !== null));
        $ema20 = $this->emaSeries($closes, 20);
        $ema50 = $this->emaSeries($closes, 50);
        $ema200 = $this->emaSeries($closes, 200);
        $atr = $this->atr($highs, $lows, $closes, 14);
        $lastClose = (float) end($closes);

        return [
            'ema20' => $this->lastNonNull($ema20),
            'ema20_previous' => $this->previousNonNull($ema20),
            'ema50' => $this->lastNonNull($ema50),
            'ema50_previous' => $this->previousNonNull($ema50),
            'ema200' => $this->lastNonNull($ema200),
            'ema200_previous' => $this->previousNonNull($ema200),
            'rsi14' => $this->rsi($closes, 14),
            'macd' => $this->macd($closes),
            'atr14' => $atr,
            'atr_percent' => $lastClose > 0 && $atr !== null ? ($atr / $lastClose) * 100 : null,
            'volume' => $this->volumeSummary($volumes),
        ];
    }

    private function emaSeries(array $values, int $period): array
    {
        if (count($values) < $period) return [];
        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $result = array_fill(0, $period - 1, null);
        $result[] = $ema;
        for ($i = $period; $i < count($values); $i++) {
            $ema = (($values[$i] - $ema) * $multiplier) + $ema;
            $result[] = $ema;
        }
        return $result;
    }

    private function lastNonNull(array $series): ?float
    {
        for ($i = count($series) - 1; $i >= 0; $i--) if ($series[$i] !== null) return (float) $series[$i];
        return null;
    }

    private function previousNonNull(array $series): ?float
    {
        $found = 0;
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i] === null) continue;
            $found++;
            if ($found === 2) return (float) $series[$i];
        }
        return null;
    }

    private function rsi(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) return null;
        $gains = $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains += max($change, 0); $losses += max(-$change, 0);
        }
        $avgGain = $gains / $period; $avgLoss = $losses / $period;
        for ($i = $period + 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $avgGain = (($avgGain * ($period - 1)) + max($change, 0)) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + max(-$change, 0)) / $period;
        }
        if ($avgLoss == 0.0) return 100.0;
        return 100 - (100 / (1 + ($avgGain / $avgLoss)));
    }

    private function macd(array $closes): array
    {
        $fast = $this->emaSeries($closes, 12); $slow = $this->emaSeries($closes, 26);
        if (!$fast || !$slow) return ['line' => null, 'signal' => null, 'histogram' => null, 'previous_histogram' => null];
        $lines = [];
        foreach ($closes as $i => $_) if (($fast[$i] ?? null) !== null && ($slow[$i] ?? null) !== null) $lines[] = $fast[$i] - $slow[$i];
        $signalSeries = $this->emaSeries($lines, 9);
        $histograms = [];
        foreach ($lines as $i => $line) if (($signalSeries[$i] ?? null) !== null) $histograms[] = $line - $signalSeries[$i];
        return [
            'line' => $lines ? (float) end($lines) : null,
            'signal' => $this->lastNonNull($signalSeries),
            'histogram' => $histograms ? (float) end($histograms) : null,
            'previous_histogram' => count($histograms) > 1 ? (float) $histograms[count($histograms) - 2] : null,
        ];
    }

    private function atr(array $highs, array $lows, array $closes, int $period): ?float
    {
        if (count($closes) <= $period) return null;
        $trs = [];
        for ($i = 1; $i < count($closes); $i++) $trs[] = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++) $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        return $atr;
    }

    private function volumeSummary(array $volumes): array
    {
        if (count($volumes) < 20) return ['available' => false, 'current' => null, 'average20' => null, 'relative' => null];
        $current = (float) end($volumes); $average = array_sum(array_slice($volumes, -20)) / 20;
        return ['available' => true, 'current' => $current, 'average20' => $average, 'relative' => $average > 0 ? $current / $average : null];
    }
}
