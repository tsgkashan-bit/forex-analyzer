<?php

namespace Tests\Feature;

use Tests\TestCase;

final class TradingAnalysisValidationTest extends TestCase
{
    public function test_pair_and_timeframe_are_required(): void
    {
        $this->postJson('/api/trading/analyze', [])->assertStatus(422)->assertJsonValidationErrors(['pair', 'timeframe']);
    }

    public function test_invalid_pair_characters_are_rejected(): void
    {
        $this->postJson('/api/trading/analyze', ['pair' => '<script>', 'timeframe' => '15M'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pair']);
    }
}
