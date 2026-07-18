<?php

namespace App\Services\Analysis;

final class TradeSetupBuilder
{
    public function build(float $price, array $indicators, array $structure, array $multiTimeframe = []): array
    {
        $score = 50.0; $bull = []; $bear = [];
        $trend = $structure['trend'] ?? 'Ranging / Mixed';

        if (str_starts_with($trend, 'Bullish')) { $score += $trend === 'Bullish' ? 12 : 7; $bull[] = 'EMA trend/bias is bullish'; }
        if (str_starts_with($trend, 'Bearish')) { $score -= $trend === 'Bearish' ? 12 : 7; $bear[] = 'EMA trend/bias is bearish'; }
        if (($structure['market_structure'] ?? '') === 'Higher highs / higher lows') { $score += 8; $bull[] = 'Higher-high / higher-low structure'; }
        if (($structure['market_structure'] ?? '') === 'Lower highs / lower lows') { $score -= 8; $bear[] = 'Lower-high / lower-low structure'; }
        if (($structure['bos'] ?? 'none') === 'bullish') { $score += 12; $bull[] = 'Bullish break of structure'; }
        if (($structure['bos'] ?? 'none') === 'bearish') { $score -= 12; $bear[] = 'Bearish break of structure'; }
        if (($structure['choch'] ?? 'none') === 'bullish') { $score += 7; $bull[] = 'Bullish change of character'; }
        if (($structure['choch'] ?? 'none') === 'bearish') { $score -= 7; $bear[] = 'Bearish change of character'; }

        $rsi = (float) ($indicators['rsi14'] ?? 50);
        if ($rsi >= 52 && $rsi <= 68) { $score += 5; $bull[] = 'RSI supports bullish momentum'; }
        elseif ($rsi <= 48 && $rsi >= 32) { $score -= 5; $bear[] = 'RSI supports bearish momentum'; }
        elseif ($rsi < 30) { $score += 3; $bull[] = 'RSI is oversold; reversal confirmation is still required'; }
        elseif ($rsi > 70) { $score -= 3; $bear[] = 'RSI is overbought; reversal confirmation is still required'; }

        $hist = (float) ($indicators['macd']['histogram'] ?? 0); $prevHist = (float) ($indicators['macd']['previous_histogram'] ?? $hist);
        if ($hist > 0) { $score += $hist >= $prevHist ? 6 : 3; $bull[] = 'MACD histogram is positive'; }
        if ($hist < 0) { $score -= abs($hist) >= abs($prevHist) ? 6 : 3; $bear[] = 'MACD histogram is negative'; }

        $pattern = $structure['candlestick_pattern'] ?? '';
        if (in_array($pattern, ['Bullish engulfing', 'Hammer-like rejection'], true)) { $score += 5; $bull[] = $pattern; }
        if (in_array($pattern, ['Bearish engulfing', 'Upper-wick rejection'], true)) { $score -= 5; $bear[] = $pattern; }
        if ($pattern === 'Doji / indecision') { $bull[] = 'Doji signals indecision'; $bear[] = 'Doji signals indecision'; }

        $mtfBull = count(array_filter($multiTimeframe, fn ($row) => str_starts_with($row['trend'] ?? '', 'Bullish')));
        $mtfBear = count(array_filter($multiTimeframe, fn ($row) => str_starts_with($row['trend'] ?? '', 'Bearish')));
        if ($mtfBull > $mtfBear) { $score += min(12, $mtfBull * 6); $bull[] = 'Higher-timeframe confirmation is bullish'; }
        if ($mtfBear > $mtfBull) { $score -= min(12, $mtfBear * 6); $bear[] = 'Higher-timeframe confirmation is bearish'; }
        if ($mtfBull > 0 && $mtfBear > 0) { $bull[] = 'Higher timeframes conflict'; $bear[] = 'Higher timeframes conflict'; }

        $score = max(0, min(100, $score));
        $direction = $score >= 65 ? 'BUY' : ($score <= 35 ? 'SELL' : 'WAIT');
        $bias = $score >= 57 ? 'BUY' : ($score <= 43 ? 'SELL' : 'NEUTRAL');
        $confidence = $direction === 'WAIT'
            ? (int) round(max(40, min(74, 50 + abs($score - 50) * 1.6)))
            : (int) round(max(65, min(92, 65 + abs($score - 50) * 1.1)));

        $atr = max((float) ($indicators['atr14'] ?? 0), $price * 0.001);
        $buyTrigger = max((float) $structure['resistance'], (float) ($structure['fvg']['to'] ?? 0)) + ($atr * 0.08);
        $sellTriggerBase = (float) $structure['support'];
        if (($structure['fvg']['type'] ?? null) === 'bearish') $sellTriggerBase = min($sellTriggerBase, (float) $structure['fvg']['from']);
        $sellTrigger = $sellTriggerBase - ($atr * 0.08);

        if ($direction === 'WAIT') {
            $category = $this->waitCategory($structure, $indicators, $multiTimeframe, $bias);
            return [
                'direction' => 'WAIT', 'bias' => $bias, 'confidence' => $confidence,
                'entry' => null, 'stop_loss' => null, 'take_profits' => [], 'risk_reward' => null,
                'reasons' => array_values(array_unique(array_merge($bull, $bear, ['A confirmed trigger has not completed yet']))),
                'invalidation' => 'No trade is active. Wait for a candle close beyond a trigger and then a valid retest/continuation.',
                'wait_category' => $category,
                'conditional_setup' => [
                    'buy_above' => $buyTrigger,
                    'sell_below' => $sellTrigger,
                    'preferred_bias' => $bias,
                    'instruction' => 'Re-analyze after the current candle closes or when either trigger is confirmed by a candle close.',
                ],
            ];
        }

        if ($direction === 'BUY') {
            $entry = max((float) $structure['support'], $price - ($atr * 0.20));
            $stop = min((float) $structure['demand_zone'][0], $entry - ($atr * 1.15));
            $risk = max($entry - $stop, $atr * 0.8);
            $targets = [$entry + ($risk * 1.5), $entry + ($risk * 2.2), $entry + ($risk * 3.0)];
            $reasons = $bull;
        } else {
            $entry = min((float) $structure['resistance'], $price + ($atr * 0.20));
            $stop = max((float) $structure['supply_zone'][1], $entry + ($atr * 1.15));
            $risk = max($stop - $entry, $atr * 0.8);
            $targets = [$entry - ($risk * 1.5), $entry - ($risk * 2.2), $entry - ($risk * 3.0)];
            $reasons = $bear;
        }

        return [
            'direction' => $direction, 'bias' => $bias, 'confidence' => $confidence,
            'entry' => $entry, 'stop_loss' => $stop, 'take_profits' => $targets,
            'risk_reward' => '1 : 3', 'reasons' => array_values(array_unique($reasons)),
            'invalidation' => $direction === 'BUY' ? 'A decisive close below the demand zone / protected swing low.' : 'A decisive close above the supply zone / protected swing high.',
            'wait_category' => null, 'conditional_setup' => null,
        ];
    }

    private function waitCategory(array $structure, array $indicators, array $multiTimeframe, string $bias): string
    {
        $atrPercent = (float) ($indicators['atr_percent'] ?? 0);
        $trends = array_values(array_filter(array_map(fn ($row) => $row['trend'] ?? null, $multiTimeframe)));
        $bull = count(array_filter($trends, fn ($trend) => str_starts_with($trend, 'Bullish')));
        $bear = count(array_filter($trends, fn ($trend) => str_starts_with($trend, 'Bearish')));
        if ($atrPercent > 0 && $atrPercent < 0.035) return 'Low volatility';
        if ($bull > 0 && $bear > 0) return 'Conflicting timeframes';
        if (($structure['market_structure'] ?? '') === 'Transitional / range' || ($structure['trend'] ?? '') === 'Ranging / Mixed') return 'Market ranging / mixed';
        if ($bias !== 'NEUTRAL') return 'Conditional setup — waiting for confirmation';
        return 'Insufficient directional confluence';
    }
}
