<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GeminiAnalysisWriter
{
    public function available(): bool
    {
        return filled(config('trading.gemini.key'));
    }

    public function write(array $payload, ?string $imageDataUrl = null): array
    {
        if (!$this->available()) throw new RuntimeException('Gemini API key is not configured.');

        $parts = [['text' => $this->prompt($payload, $imageDataUrl !== null)]];
        if ($imageDataUrl) {
            [$mimeType, $base64] = $this->parseDataUrl($imageDataUrl);
            $parts[] = ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]];
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.10,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'screenshot_observations' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                    ],
                    'required' => ['summary', 'screenshot_observations'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        $model = $this->resolveModel();
        $response = $this->request($model, $body);
        if ($response->failed() && $response->status() === 404) {
            Cache::forget('gemini:resolved-model');
            $model = $this->discoverModel(exclude: [$model]);
            $response = $this->request($model, $body);
        }
        if ($response->failed()) throw new RuntimeException(data_get($response->json(), 'error.message', 'Gemini request failed.'));

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') throw new RuntimeException('Gemini response did not contain text output.');
        $decoded = json_decode($this->cleanJson($text), true);
        if (!is_array($decoded)) throw new RuntimeException('Gemini returned invalid JSON output.');

        return [
            'summary' => (string) ($decoded['summary'] ?? ''),
            'screenshot_observations' => isset($decoded['screenshot_observations']) ? (string) $decoded['screenshot_observations'] : null,
            'ai_available' => true, 'provider' => 'Gemini ('.$model.')', 'warning' => null,
        ];
    }

    private function request(string $model, array $body): Response
    {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
        return Http::acceptJson()->timeout(90)->retry(2, 750, throw: false)
            ->post($endpoint.'?key='.urlencode((string) config('trading.gemini.key')), $body);
    }

    private function resolveModel(): string
    {
        return Cache::remember('gemini:resolved-model', now()->addHours(6), function (): string {
            $configured = trim((string) config('trading.gemini.model'));
            return $configured !== '' ? $configured : $this->discoverModel();
        });
    }

    private function discoverModel(array $exclude = []): string
    {
        $response = Http::acceptJson()->timeout(30)->get('https://generativelanguage.googleapis.com/v1beta/models', [
            'key' => config('trading.gemini.key'), 'pageSize' => 100,
        ]);
        if ($response->failed()) throw new RuntimeException(data_get($response->json(), 'error.message', 'Unable to discover an available Gemini model.'));

        $models = collect($response->json('models', []))
            ->filter(fn (array $model) => in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true))
            ->map(fn (array $model) => str_replace('models/', '', (string) ($model['name'] ?? '')))
            ->filter(fn (string $name) => $name !== '' && !in_array($name, $exclude, true) && str_contains($name, 'flash') && !str_contains($name, 'live') && !str_contains($name, 'image'))
            ->values();

        $preferred = ['gemini-3.5-flash', 'gemini-3-flash', 'gemini-3.1-flash-lite', 'gemini-3-flash-preview'];
        foreach ($preferred as $name) if ($models->contains($name)) return $name;
        $selected = $models->first();
        if (!$selected) throw new RuntimeException('No compatible Gemini Flash model is available for this API key.');
        return $selected;
    }

    private function parseDataUrl(string $dataUrl): array
    {
        if (!preg_match('/^data:(image\/(?:png|jpeg|webp));base64,(.+)$/s', $dataUrl, $matches)) throw new RuntimeException('Unsupported screenshot format.');
        return [$matches[1], $matches[2]];
    }

    private function cleanJson(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        return trim($text);
    }

    private function prompt(array $payload, bool $hasImage): string
    {
        $imageInstructions = $hasImage
            ? 'Inspect the attached trading chart. Report only clearly visible pair/timeframe labels, trend, support/resistance, trendlines, zones, order blocks, liquidity, FVGs, candlestick patterns and chart patterns. Do not invent unreadable values.'
            : 'No screenshot is attached. Set screenshot_observations to null.';
        return "You are a cautious chart-analysis assistant. {$imageInstructions} Use deterministic values exactly as supplied. Never alter direction, confidence, entry, stop or targets. Never guarantee profit. Return concise JSON. Data:\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
