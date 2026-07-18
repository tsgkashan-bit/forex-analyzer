<?php

use App\Http\Controllers\TradingAnalysisController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/trading');

Route::get('/trading', [TradingAnalysisController::class, 'index'])->name('trading.index');
Route::post('/api/trading/analyze', [TradingAnalysisController::class, 'analyze'])
    ->middleware('throttle:20,1')
    ->name('trading.analyze');
