(() => {
  'use strict';
  const count = document.getElementById('ongoing-trip-count');
  const status = document.getElementById('ongoing-trip-update');
  if (!count || !status) return;
  let timer, busy = false;
  async function refresh() {
    clearTimeout(timer);
    if (document.hidden || busy) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('api/admin-trip-count.php', {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { Accept: 'application/json' }
      });
      if (!response.ok || response.redirected) throw new Error('Unavailable');
      const result = await response.json();
      if (!Number.isSafeInteger(result.ongoing_trips) || result.ongoing_trips < 0) throw new Error('Invalid count');
      count.textContent = String(result.ongoing_trips);
      status.textContent = 'Updates every 5 seconds';
      status.title = `Last updated ${new Date().toLocaleTimeString()}`;
    } catch (_) {
      status.textContent = 'Update unavailable  -  retrying';
    } finally {
      clearTimeout(timeout);
      busy = false;
      if (!document.hidden) timer = setTimeout(refresh, 5000);
    }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(timer);
    else refresh();
  });
  refresh();
})();
