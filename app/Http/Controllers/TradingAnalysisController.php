<?php

namespace App\Http\Controllers;

use App\Services\Analysis\TradingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TradingAnalysisController extends Controller
{
    public function index(): View
    {
        return view('trading');
    }

    public function analyze(Request $request, TradingAnalysisService $service): JsonResponse
    {
        $validated = $request->validate([
            'pair' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\/_-]+$/'],
            'timeframe' => ['required', 'in:1M,5M,15M,30M,1H,4H,Daily'],
            'current_price' => ['nullable', 'numeric', 'gt:0'],
            'chart' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        return response()->json($service->analyze(
            pair: $validated['pair'],
            timeframe: $validated['timeframe'],
            providedPrice: isset($validated['current_price']) ? (float) $validated['current_price'] : null,
            chart: $request->file('chart'),
        ));
    }
}
