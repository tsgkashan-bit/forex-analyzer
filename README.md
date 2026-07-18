# Trading Analysis Bot — Improved Build

## Improvements
- New-candle lock: repeated requests during the same candle reuse the previous result.
- Multi-timeframe confirmation.
- Explicit WAIT categories and conditional BUY/SELL triggers.
- Binance public candles for `*USDT` crypto symbols; Twelve Data for Forex, metals and other supported symbols.
- Provider response caching to reduce free API usage.
- Gemini is used only when a screenshot is uploaded; ordinary analysis uses a deterministic local summary.
- Automatic Gemini Flash model fallback/discovery if the configured model is unavailable.
- Price precision formatting and clearer recheck timing.

## Update an existing local installation
Preserve your existing `.env`. Replace the project files with this build, then run:

```bat
set PATH=C:\wamp64\bin\php\php8.3.14;%PATH%
composer install
php artisan optimize:clear
php artisan migrate
php artisan serve
```

Recommended `.env` value:

```env
GEMINI_MODEL=gemini-3.5-flash
```

The code can automatically discover another compatible Flash model when a configured model returns 404.
