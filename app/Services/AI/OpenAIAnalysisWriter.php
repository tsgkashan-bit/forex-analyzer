<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAIAnalysisWriter
{
    public function available(): bool
    {
        return filled(config('trading.openai.key'));
    }

    public function write(array $payload, ?string $imageDataUrl = null): array
    {
        if (!$this->available()) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($payload),
        ]];

        if ($imageDataUrl) {
            $content[] = ['type' => 'input_image', 'image_url' => $imageDataUrl, 'detail' => 'high'];
        }

        $response = Http::withToken(config('trading.openai.key'))
            ->acceptJson()
            ->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('trading.openai.model', 'gpt-4.1-mini'),
                'input' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'trading_analysis',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'summary' => ['type' => 'string'],
                                'screenshot_observations' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['summary', 'screenshot_observations'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ])->throw()->json();

        $text = data_get($response, 'output.0.content.0.text');
        if (!$text) throw new RuntimeException('AI response did not contain structured output.');

        return array_merge(json_decode($text, true, flags: JSON_THROW_ON_ERROR), [
            'ai_available' => true,
            'provider' => 'OpenAI',
        ]);
    }

    private function prompt(array $payload): string
    {
        return "You are a cautious technical-analysis narrator. Use ONLY the supplied deterministic data. "
            ."Never alter prices, direction, confidence, entry, stop, or targets. Never claim certainty. "
            ."If an image is attached, describe only clearly visible chart features and state uncertainty where labels are unreadable. "
            ."Return a concise professional explanation and screenshot observations. Data:\n"
            .json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
