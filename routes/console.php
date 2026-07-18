<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('trading:status', function (): void {
    $this->info('Trading Analysis Bot is installed.');
    $this->line('Twelve Data: '.(config('trading.providers.twelve_data.key') ? 'configured' : 'not configured'));
    $this->line('Gemini: '.(config('trading.gemini.key') ? 'configured' : 'not configured'));
    $this->line('OpenAI fallback: '.(config('trading.openai.key') ? 'configured' : 'optional / not configured'));
})->purpose('Check trading bot configuration');
