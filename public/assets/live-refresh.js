'use strict';

(() => {
  const endpoint = 'api/live-update-token.php';
  const interval = 2000;
  let token = null;
  let requestInFlight = false;
  let refreshPending = false;

  const isSafeToRefresh = () => {
    if (document.visibilityState !== 'visible') return false;
    if (document.querySelector('dialog[open], [role="dialog"][aria-hidden="false"]')) return false;
    const active = document.activeElement;
    return !active || !/^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName);
  };

  const refreshWhenSafe = () => {
    if (!refreshPending || !isSafeToRefresh()) return;
    refreshPending = false;
    window.location.reload();
  };

  const checkForChanges = async () => {
    if (requestInFlight || document.visibilityState !== 'visible') return;
    requestInFlight = true;
    try {
      const response = await fetch(`${endpoint}?_=${Date.now()}`, {
        credentials: 'same-origin', cache: 'no-store',
      });
      if (response.status === 401) return;
      if (!response.ok) throw new Error('Live update check failed.');
      const update = await response.json();
      if (typeof update.token !== 'string') return;
      if (token !== null && token !== update.token) {
        refreshPending = true;
        window.dispatchEvent(new CustomEvent('system:updated'));
        refreshWhenSafe();
      }
      token = update.token;
    } catch (error) {
      console.debug('Live updates are temporarily unavailable.', error);
    } finally {
      requestInFlight = false;
    }
  };

  ['focusin', 'change', 'blur'].forEach(eventName => document.addEventListener(eventName, refreshWhenSafe));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      checkForChanges();
      refreshWhenSafe();
    }
  });
  checkForChanges();
  window.setInterval(checkForChanges, interval);
})();
