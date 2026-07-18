<?php

namespace App\Services\Analysis;

use App\Data\Candle;

final class StructureAnalyzer
{
    public function analyze(array $candles, array $indicators): array
    {
        $last = end($candles);
        $swings = $this->swings($candles, 3);
        $recentHighs = array_slice($swings['highs'], -5);
        $recentLows = array_slice($swings['lows'], -5);
        $trend = $this->trend($last->close, $indicators);
        $atr = max((float) ($indicators['atr14'] ?? 0), $last->close * 0.0005);

        $supportCandidates = array_values(array_filter(array_column($recentLows, 'price'), fn ($value) => $value < $last->close));
        $resistanceCandidates = array_values(array_filter(array_column($recentHighs, 'price'), fn ($value) => $value > $last->close));
        $fallback = array_slice($candles, -40);
        $support = $supportCandidates ? max($supportCandidates) : min(array_map(fn (Candle $c) => $c->low, $fallback));
        $resistance = $resistanceCandidates ? min($resistanceCandidates) : max(array_map(fn (Candle $c) => $c->high, $fallback));

        if ($support >= $last->close) $support = $last->close - $atr;
        if ($resistance <= $last->close) $resistance = $last->close + $atr;

        $previousHigh = $recentHighs ? (float) end($recentHighs)['price'] : $resistance;
        $previousLow = $recentLows ? (float) end($recentLows)['price'] : $support;
        $bos = $last->close > ($previousHigh + ($atr * 0.05)) ? 'bullish' : ($last->close < ($previousLow - ($atr * 0.05)) ? 'bearish' : 'none');

        return [
            'trend' => $trend,
            'market_structure' => $this->structureLabel($recentHighs, $recentLows),
            'support' => $support,
            'resistance' => $resistance,
            'supply_zone' => [$resistance, $resistance + ($atr * 0.35)],
            'demand_zone' => [$support - ($atr * 0.35), $support],
            'bos' => $bos,
            'choch' => $this->choch($recentHighs, $recentLows),
            'liquidity' => [
                'buy_side' => $recentHighs ? max(array_column($recentHighs, 'price')) : $resistance,
                'sell_side' => $recentLows ? min(array_column($recentLows, 'price')) : $support,
            ],
            'fvg' => $this->latestFvg($candles),
            'order_block' => $this->orderBlock($candles, $bos),
            'candlestick_pattern' => $this->candlestickPattern($candles),
            'range_width_atr' => ($resistance - $support) / $atr,
        ];
    }

    private function swings(array $candles, int $window): array
    {
        $highs = $lows = [];
        for ($i = $window; $i < count($candles) - $window; $i++) {
            $slice = array_slice($candles, $i - $window, ($window * 2) + 1);
            $high = max(array_map(fn (Candle $c) => $c->high, $slice));
            $low = min(array_map(fn (Candle $c) => $c->low, $slice));
            if ($candles[$i]->high >= $high) $highs[] = ['index' => $i, 'price' => $candles[$i]->high];
            if ($candles[$i]->low <= $low) $lows[] = ['index' => $i, 'price' => $candles[$i]->low];
        }
        return compact('highs', 'lows');
    }

    private function trend(float $price, array $i): string
    {
        $ema20 = $i['ema20'] ?? null; $ema50 = $i['ema50'] ?? null; $ema200 = $i['ema200'] ?? null;
        $ema20Prev = $i['ema20_previous'] ?? $ema20; $ema50Prev = $i['ema50_previous'] ?? $ema50;
        if ($ema20 && $ema50 && $ema200 && $price > $ema20 && $ema20 > $ema50 && $ema50 > $ema200 && $ema20 >= $ema20Prev && $ema50 >= $ema50Prev) return 'Bullish';
        if ($ema20 && $ema50 && $ema200 && $price < $ema20 && $ema20 < $ema50 && $ema50 < $ema200 && $ema20 <= $ema20Prev && $ema50 <= $ema50Prev) return 'Bearish';
        if ($ema20 && $ema50 && $price > $ema20 && $ema20 > $ema50) return 'Bullish bias';
        if ($ema20 && $ema50 && $price < $ema20 && $ema20 < $ema50) return 'Bearish bias';
        return 'Ranging / Mixed';
    }

    private function structureLabel(array $highs, array $lows): string
    {
        if (count($highs) < 2 || count($lows) < 2) return 'Insufficient swing confirmation';
        $hh = $highs[array_key_last($highs)]['price'] > $highs[count($highs) - 2]['price'];
        $hl = $lows[array_key_last($lows)]['price'] > $lows[count($lows) - 2]['price'];
        return $hh && $hl ? 'Higher highs / higher lows' : (!$hh && !$hl ? 'Lower highs / lower lows' : 'Transitional / range');
    }

    private function choch(array $highs, array $lows): string
    {
        if (count($highs) < 3 || count($lows) < 3) return 'none';
        $h = array_column(array_slice($highs, -3), 'price'); $l = array_column(array_slice($lows, -3), 'price');
        if ($h[0] > $h[1] && $h[2] > $h[1] && $l[2] > $l[1]) return 'bullish';
        if ($l[0] < $l[1] && $l[2] < $l[1] && $h[2] < $h[1]) return 'bearish';
        return 'none';
    }

    private function latestFvg(array $candles): ?array
    {
        for ($i = count($candles) - 1; $i >= max(2, count($candles) - 30); $i--) {
            $a = $candles[$i - 2]; $c = $candles[$i];
            if ($c->low > $a->high) return ['type' => 'bullish', 'from' => $a->high, 'to' => $c->low];
            if ($c->high < $a->low) return ['type' => 'bearish', 'from' => $c->high, 'to' => $a->low];
        }
        return null;
    }

    private function orderBlock(array $candles, string $bos): ?array
    {
        if ($bos === 'none') return null;
        for ($i = count($candles) - 2; $i >= max(0, count($candles) - 20); $i--) {
            $c = $candles[$i];
            if ($bos === 'bullish' && $c->close < $c->open) return ['type' => 'bullish', 'low' => $c->low, 'high' => $c->high];
            if ($bos === 'bearish' && $c->close > $c->open) return ['type' => 'bearish', 'low' => $c->low, 'high' => $c->high];
        }
        return null;
    }

    private function candlestickPattern(array $candles): string
    {
        if (count($candles) < 2) return 'none';
        $prev = $candles[count($candles) - 2]; $last = $candles[count($candles) - 1];
        $lastBody = abs($last->close - $last->open); $range = max($last->high - $last->low, PHP_FLOAT_EPSILON);
        if ($last->close > $last->open && $prev->close < $prev->open && $last->open <= $prev->close && $last->close >= $prev->open) return 'Bullish engulfing';
        if ($last->close < $last->open && $prev->close > $prev->open && $last->open >= $prev->close && $last->close <= $prev->open) return 'Bearish engulfing';
        if ($lastBody / $range < 0.12) return 'Doji / indecision';
        if (($last->open - $last->low) > ($lastBody * 2) && ($last->high - $last->close) < $lastBody) return 'Hammer-like rejection';
        if (($last->high - max($last->open, $last->close)) > ($lastBody * 2)) return 'Upper-wick rejection';
        return 'No high-confidence pattern';
    }
}
