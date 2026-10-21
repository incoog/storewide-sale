(() => {
    'use strict';
    const status = document.getElementById('sws-progress');
    if (!status || status.dataset.work !== '1' || typeof swsAdmin === 'undefined') return;
    let stopped = false;
    document.querySelectorAll('.sws-form').forEach(form => form.addEventListener('submit', () => { stopped = true; }));
    const tick = async () => {
        if (stopped) return;
        status.textContent = 'Arbetar med produktpriserna…';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 45000);
        try {
            const response = await fetch(swsAdmin.url, { method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams({ action: 'sws_process', nonce: swsAdmin.nonce }) });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data?.message || 'Körningen kunde inte fortsätta. Ladda om sidan.');
            const data = result.data;
            if (stopped) return;
            if (data.error) throw new Error(data.error);
            if (data.counts.conflicts || data.phase !== status.dataset.phase || data.phase === 'active' && !data.counts.pending) { location.reload(); return; }
            status.textContent = `Arbetar… ${data.counts.managed || 0} hanterade, ${data.counts.done || 0} klara.`;
            setTimeout(tick, 2000);
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'Körningen tog för lång tid. Ladda om sidan och fortsätt körningen.' : error.message;
        } finally { clearTimeout(timeout); }
    };
    setTimeout(tick, 1200);
})();
