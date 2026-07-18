<?php

namespace App\Services\Analysis;

use App\Data\Candle;
use App\Services\AI\AIAnalysisWriter;
use App\Services\MarketData\MarketDataManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class TradingAnalysisService
{
    public function __construct(
        private MarketDataManager $marketData,
        private IndicatorCalculator $indicators,
        private StructureAnalyzer $structure,
        private TradeSetupBuilder $setup,
        private AIAnalysisWriter $writer,
    ) {}

    public function analyze(string $pair, string $timeframe, ?float $providedPrice = null, ?UploadedFile $chart = null): array
    {
        $pair = strtoupper(trim($pair));
        $timeframe = strtoupper(trim($timeframe));

        try {
            $market = $this->marketData->candles($pair, $timeframe, 320);
            $candles = $market['candles'];
            $provider = $market['provider'];
        } catch (Throwable $e) {
            report($e);
            return $this->unavailableResult($pair, $timeframe, $providedPrice, $chart, $e->getMessage());
        }

        if (!$candles) {
            return $this->unavailableResult($pair, $timeframe, $providedPrice, $chart, 'No verified candles were returned.');
        }

        /** @var Candle $last */
        $last = end($candles);
        $price = $providedPrice ?? $last->close;
        $analysisCacheKey = 'analysis-result:'.sha1($pair.'|'.$timeframe.'|'.$last->time.'|'.($providedPrice ?? 'provider'));

        if (!$chart && Cache::has($analysisCacheKey)) {
            $cached = Cache::get($analysisCacheKey);
            $cached['reused_analysis'] = true;
            $cached['analysis_notice'] = 'No new '.$timeframe.' candle has closed. The previous analysis remains valid.';
            return $cached;
        }

        $indicatorData = $this->indicators->calculate($candles);
        $structureData = $this->structure->analyze($candles, $indicatorData);
        $multiTimeframe = $this->multiTimeframeAnalysis($pair, $timeframe);
        $setup = $this->setup->build($price, $indicatorData, $structureData, $multiTimeframe);
        $nextCandleClose = $this->nextCandleClose($last->time, $timeframe);

        $result = array_merge([
            'pair' => $pair,
            'timeframe' => $timeframe,
            'live_data' => true,
            'provider' => $provider,
            'current_price' => $price,
            'candle_time_utc' => $last->time,
            'next_candle_close_utc' => $nextCandleClose,
            'warning' => null,
            'indicators' => $indicatorData,
            'structure' => $structureData,
            'multi_timeframe' => $multiTimeframe,
            'reused_analysis' => false,
            'analysis_notice' => null,
        ], $setup, [
            'technical_reasons' => $setup['reasons'],
            'trade_management' => [
                'break_even' => 'Move stop to break even only after TP1 or a confirmed favorable structure break.',
                'partials' => 'Example: 40% at TP1, 30% at TP2, and trail the remaining 30% toward TP3.',
                'risk' => 'Risk a small fixed percentage per trade and account for spread, slippage, news and market gaps.',
            ],
            'disclaimer' => config('trading.disclaimer'),
        ]);

        $image = $chart ? 'data:'.$chart->getMimeType().';base64,'.base64_encode($chart->get()) : null;
        $result['ai'] = $image
            ? $this->writer->write($result, $image)
            : $this->localNarrative($result);

        Cache::put($analysisCacheKey, $result, now()->addSeconds($this->analysisCacheSeconds($timeframe)));

        return $result;
    }

    private function multiTimeframeAnalysis(string $pair, string $timeframe): array
    {
        $confirmations = match ($timeframe) {
            '1M' => ['5M', '15M'],
            '5M' => ['15M', '1H'],
            '15M', '30M' => ['1H', '4H'],
            '1H' => ['4H', 'Daily'],
            '4H' => ['Daily'],
            default => [],
        };

        $rows = [];
        foreach ($confirmations as $confirmation) {
            try {
                $market = $this->marketData->candles($pair, $confirmation, 220);
                $candles = $market['candles'];
                $indicators = $this->indicators->calculate($candles);
                $structure = $this->structure->analyze($candles, $indicators);
                $rows[] = [
                    'timeframe' => $confirmation,
                    'trend' => $structure['trend'],
                    'market_structure' => $structure['market_structure'],
                    'bos' => $structure['bos'],
                    'rsi' => $indicators['rsi14'],
                    'provider' => $market['provider'],
                ];
            } catch (Throwable $e) {
                report($e);
                $rows[] = ['timeframe' => $confirmation, 'trend' => 'Unavailable', 'market_structure' => null, 'bos' => null, 'rsi' => null, 'provider' => null];
            }
        }
        return $rows;
    }

    private function unavailableResult(string $pair, string $timeframe, ?float $providedPrice, ?UploadedFile $chart, string $reason): array
    {
        $result = [
            'pair' => $pair, 'timeframe' => $timeframe, 'live_data' => false, 'provider' => null,
            'current_price' => $providedPrice, 'warning' => 'Market data unavailable: '.$reason,
            'direction' => 'WAIT', 'bias' => 'NEUTRAL', 'confidence' => 0, 'entry' => null, 'stop_loss' => null,
            'take_profits' => [], 'risk_reward' => null, 'technical_reasons' => ['Verified candle data is unavailable.'],
            'wait_category' => 'Data unavailable', 'conditional_setup' => null, 'multi_timeframe' => [],
            'trade_management' => ['break_even' => null, 'partials' => null, 'risk' => 'Do not take a price-based trade without verified candle data.'],
            'invalidation' => 'No setup was generated.', 'reused_analysis' => false, 'analysis_notice' => null,
            'disclaimer' => config('trading.disclaimer'),
        ];
        $image = $chart ? 'data:'.$chart->getMimeType().';base64,'.base64_encode($chart->get()) : null;
        $result['ai'] = $image ? $this->writer->write($result, $image) : $this->localNarrative($result);
        return $result;
    }

    private function localNarrative(array $result): array
    {
        $trend = $result['structure']['trend'] ?? 'unavailable';
        $category = $result['wait_category'] ?? null;
        $summary = $result['direction'] === 'WAIT'
            ? sprintf('%s is currently a WAIT setup on %s. Reason: %s. The system has analyzed the available candles and is waiting for a confirmed trigger rather than forcing a trade.', $result['pair'], $result['timeframe'], $category ?: 'insufficient confluence')
            : sprintf('%s has a %s setup on %s with %s trend evidence and %d%% confidence. Use the listed invalidation and risk controls.', $result['pair'], $result['direction'], $result['timeframe'], strtolower($trend), $result['confidence']);

        return ['summary' => $summary, 'screenshot_observations' => null, 'ai_available' => false, 'provider' => 'Deterministic local summary', 'warning' => null];
    }

    private function nextCandleClose(string $time, string $timeframe): ?string
    {
        try {
            $start = CarbonImmutable::parse($time, 'UTC');
            return match ($timeframe) {
                '1M' => $start->addMinute()->toIso8601String(),
                '5M' => $start->addMinutes(5)->toIso8601String(),
                '15M' => $start->addMinutes(15)->toIso8601String(),
                '30M' => $start->addMinutes(30)->toIso8601String(),
                '1H' => $start->addHour()->toIso8601String(),
                '4H' => $start->addHours(4)->toIso8601String(),
                'DAILY' => $start->addDay()->toIso8601String(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function analysisCacheSeconds(string $timeframe): int
    {
        return match ($timeframe) {
            '1M' => 70, '5M' => 310, '15M' => 910, '30M' => 1810,
            '1H' => 3610, '4H' => 14410, 'DAILY' => 86410, default => 60,
        };
    }
}
