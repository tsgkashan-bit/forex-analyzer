<?php

namespace App\Services\AI;

use Throwable;

final class AIAnalysisWriter
{
    public function __construct(
        private GeminiAnalysisWriter $gemini,
        private OpenAIAnalysisWriter $openAI,
    ) {}

    public function write(array $payload, ?string $imageDataUrl = null): array
    {
        $errors = [];

        if ($this->gemini->available()) {
            try {
                return $this->gemini->write($payload, $imageDataUrl);
            } catch (Throwable $e) {
                report($e);
                $errors[] = 'Gemini: '.$e->getMessage();
            }
        }

        if ($this->openAI->available()) {
            try {
                return $this->openAI->write($payload, $imageDataUrl);
            } catch (Throwable $e) {
                report($e);
                $errors[] = 'OpenAI: '.$e->getMessage();
            }
        }

        return [
            'summary' => null,
            'screenshot_observations' => null,
            'ai_available' => false,
            'provider' => null,
            'warning' => $errors
                ? implode(' | ', $errors)
                : 'No AI provider is configured. Add GEMINI_API_KEY or OPENAI_API_KEY.',
        ];
    }
}
