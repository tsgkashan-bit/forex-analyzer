<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Trading Analysis</title>
    <link rel="stylesheet" href="{{ asset('trading.css') }}">
    <script src="{{ asset('trading.js') }}" defer></script>
</head>
<body>
<main class="shell">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">TA</span><div><strong>Trade Analyst</strong><small>AI-assisted technical analysis</small></div></div>
        <div class="status"><span></span> Data-aware analysis engine</div>
        <div class="quick-title">Quick commands</div>
        <button class="quick" data-pair="XAUUSD" data-timeframe="15M">Analyze XAUUSD 15M</button>
        <button class="quick" data-pair="BTCUSDT" data-timeframe="1H">Analyze BTCUSDT 1H</button>
        <button class="quick" data-pair="EURUSD" data-timeframe="4H">Analyze EURUSD 4H</button>
    </aside>

    <section class="workspace">
        <header><div><h1>Trading Analysis Chat Bot</h1><p>Deterministic indicators, market structure and AI explanation.</p></div><div class="live-pill">● LIVE DATA WHEN AVAILABLE</div></header>

        <div id="conversation" class="conversation">
            <div class="message bot"><div class="avatar">AI</div><div class="bubble">Enter a pair and timeframe. I will only produce price levels when verified candle data is available.</div></div>
        </div>

        <form id="analysis-form" enctype="multipart/form-data">
            <div class="fields">
                <label>Trading pair<input name="pair" placeholder="XAUUSD" required></label>
                <label>Timeframe<select name="timeframe"><option>1M</option><option>5M</option><option selected>15M</option><option>30M</option><option>1H</option><option>4H</option><option>Daily</option></select></label>
                <label>Current price <small>optional</small><input name="current_price" type="number" step="any" min="0" placeholder="Provider price"></label>
                <label class="upload">Chart screenshot <small>optional</small><input name="chart" type="file" accept="image/png,image/jpeg,image/webp"></label>
            </div>
            <button class="analyze" type="submit"><span>Analyze Market</span><b>→</b></button>
        </form>
    </section>
</main>
</body>
</html>
