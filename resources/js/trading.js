const form = document.querySelector('#analysis-form');
const conversation = document.querySelector('#conversation');
const csrf = document.querySelector('meta[name="csrf-token"]').content;

const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
const precision = value => {
    const number = Math.abs(Number(value));
    if (number >= 100) return 2;
    if (number >= 1) return 4;
    return 6;
};
const formatNumber = value => value == null ? '—' : Number(value).toLocaleString(undefined, {
    minimumFractionDigits: Math.min(2, precision(value)),
    maximumFractionDigits: precision(value),
});
const formatUtc = value => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? escapeHtml(value) : `${date.toLocaleString()} (local time)`;
};

function addMessage(type, html) {
    const element = document.createElement('div');
    element.className = `message ${type}`;
    element.innerHTML = type === 'bot' ? `<div class="avatar">AI</div><div class="bubble">${html}</div>` : `<div class="bubble">${html}</div>`;
    conversation.appendChild(element);
    conversation.scrollTop = conversation.scrollHeight;
    return element;
}

function render(data) {
    const reasons = (data.technical_reasons || []).map(reason => `<li>${escapeHtml(reason)}</li>`).join('');
    const tps = data.take_profits || [];
    const ai = data.ai?.summary ? `<div class="section"><h3>Market Outlook</h3><p>${escapeHtml(data.ai.summary)}</p></div>` : '';
    const screenshot = data.ai?.screenshot_observations ? `<div class="section"><h3>Screenshot Analysis</h3><p>${escapeHtml(data.ai.screenshot_observations)}</p></div>` : '';
    const notice = data.analysis_notice ? `<p class="info-notice">${escapeHtml(data.analysis_notice)}</p>` : '';
    const waitReason = data.wait_category ? `<p class="wait-reason"><strong>WAIT reason:</strong> ${escapeHtml(data.wait_category)}</p>` : '';
    const conditional = data.conditional_setup ? `<div class="section conditional"><h3>Conditional Setup</h3>
        <div class="trigger-grid">
            <div><small>Potential BUY after close above</small><strong>${formatNumber(data.conditional_setup.buy_above)}</strong></div>
            <div><small>Potential SELL after close below</small><strong>${formatNumber(data.conditional_setup.sell_below)}</strong></div>
        </div>
        <p><strong>Preferred bias:</strong> ${escapeHtml(data.conditional_setup.preferred_bias)}</p>
        <p>${escapeHtml(data.conditional_setup.instruction)}</p>
    </div>` : '';
    const mtfRows = (data.multi_timeframe || []).map(row => `<tr><td>${escapeHtml(row.timeframe)}</td><td>${escapeHtml(row.trend)}</td><td>${escapeHtml(row.market_structure || '—')}</td><td>${escapeHtml(row.bos || '—')}</td></tr>`).join('');
    const mtf = mtfRows ? `<div class="section"><h3>Multi-Timeframe Confirmation</h3><div class="table-wrap"><table><thead><tr><th>TF</th><th>Trend</th><th>Structure</th><th>BOS</th></tr></thead><tbody>${mtfRows}</tbody></table></div></div>` : '';

    return `<div class="analysis-card">
        <div class="topline"><strong>${escapeHtml(data.pair)} · ${escapeHtml(data.timeframe)}</strong><span class="badge ${data.direction}">${data.direction}</span><span class="confidence">Confidence ${data.confidence}%</span></div>
        <div class="meter"><i style="width:${Math.max(0, Math.min(100, data.confidence))}%"></i></div>
        ${notice}${data.warning ? `<p class="warning">${escapeHtml(data.warning)}</p>` : ''}${waitReason}
        <div class="grid">
            <div class="metric"><small>Current price</small><strong>${formatNumber(data.current_price)}</strong></div>
            <div class="metric"><small>Trend</small><strong>${escapeHtml(data.structure?.trend || 'Unavailable')}</strong></div>
            <div class="metric"><small>Entry</small><strong>${formatNumber(data.entry)}</strong></div>
            <div class="metric"><small>Stop loss</small><strong>${formatNumber(data.stop_loss)}</strong></div>
            <div class="metric"><small>TP1</small><strong>${formatNumber(tps[0])}</strong></div>
            <div class="metric"><small>TP2</small><strong>${formatNumber(tps[1])}</strong></div>
            <div class="metric"><small>TP3</small><strong>${formatNumber(tps[2])}</strong></div>
            <div class="metric"><small>Risk : Reward</small><strong>${escapeHtml(data.risk_reward || '—')}</strong></div>
        </div>
        <div class="section"><h3>Market Structure</h3><p>${escapeHtml(data.structure?.market_structure || 'Unavailable')} · BOS: ${escapeHtml(data.structure?.bos || '—')} · CHOCH: ${escapeHtml(data.structure?.choch || '—')}</p><p><small>Latest candle: ${escapeHtml(data.candle_time_utc || '—')} UTC · Next meaningful recheck: ${formatUtc(data.next_candle_close_utc)}</small></p></div>
        ${mtf}
        <div class="section"><h3>Technical Reasons</h3><ul>${reasons || '<li>No verified confluence available.</li>'}</ul></div>
        ${conditional}
        <div class="section"><h3>Trade Management</h3><p>${escapeHtml(data.trade_management?.break_even || '')}</p><p>${escapeHtml(data.trade_management?.partials || '')}</p><p><strong>Invalidation:</strong> ${escapeHtml(data.invalidation || '—')}</p></div>
        ${ai}${screenshot}
        <div class="section"><small>${escapeHtml(data.live_data ? `Market data: ${data.provider}` : 'No live market data')} · ${escapeHtml(data.disclaimer)}</small></div>
    </div>`;
}

form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const payload = new FormData(form);
    addMessage('user', `Analyze <strong>${escapeHtml(payload.get('pair'))}</strong> on <strong>${escapeHtml(payload.get('timeframe'))}</strong>`);
    const loading = addMessage('bot', 'Checking current and higher timeframes…');
    button.disabled = true;

    try {
        const response = await fetch('/api/trading/analyze', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: payload });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' '));
        loading.querySelector('.bubble').innerHTML = render(data);
    } catch (error) {
        loading.querySelector('.bubble').innerHTML = `<span class="warning">${escapeHtml(error.message || 'Analysis failed.')}</span>`;
    } finally {
        button.disabled = false;
    }
});

document.querySelectorAll('.quick').forEach(button => button.addEventListener('click', () => {
    form.elements.pair.value = button.dataset.pair;
    form.elements.timeframe.value = button.dataset.timeframe;
    form.requestSubmit();
}));
