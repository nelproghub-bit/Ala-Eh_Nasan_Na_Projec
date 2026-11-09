(() => {
  'use strict';
  const form = document.getElementById('seat-form');
  if (!form) return;
  const input = document.getElementById('seat-occupied');
  const capacity = Number(form.dataset.capacity);
  const seatEndpoint = form.dataset.seatEndpoint || form.action;
  let savedCount = Number(input.value);
  let saveQueue = Promise.resolve();
  const status = document.getElementById('seat-update-status');
  const initialStatus = status.textContent;
  const minus = document.getElementById('seat-minus');
  const plus = document.getElementById('seat-plus');
  minus.hidden = false; plus.hidden = false;
  form.querySelector('.seat-quick-actions').hidden = false;
  function render() {
    const occupied = input.value === '' ? NaN : Number(input.value);
    const valid = Number.isInteger(occupied) && occupied >= 0 && occupied <= capacity;
    input.setCustomValidity(valid ? '' : `Enter a whole number from 0 to ${capacity}.`);
    minus.disabled = !valid || occupied === 0;
    plus.disabled = !valid || occupied === capacity;
    if (!valid) { status.textContent = `Enter a whole number from 0 to ${capacity}. Your saved count has not changed.`; return; }
    const percentage = occupied / capacity * 100;
    const available = capacity - occupied;
    const color = percentage >= 90 ? 'red' : percentage >= 70 ? 'orange' : percentage >= 50 ? 'yellow' : 'green';
    const label = available === 0 ? 'Full' : percentage >= 90 ? 'Almost full' : percentage >= 70 ? 'Filling up' : percentage >= 50 ? 'Half full' : 'Seats available';
    document.getElementById('seat-readout').className = `seat-readout seat-color-${color}`;
    document.getElementById('seat-state-label').textContent = label;
    document.getElementById('seat-available').textContent = available;
    document.getElementById('seat-available-percent').textContent = `${Math.round(available / capacity * 100)}% available`;
    document.getElementById('seat-occupied-note').textContent = `${occupied} occupied  -  ${Math.round(percentage)}% occupied`;
    const meter = document.getElementById('seat-meter');
    meter.setAttribute('aria-valuenow', occupied);
    meter.firstElementChild.style.width = `${percentage}%`;
    status.textContent = Number(input.value) === savedCount ? initialStatus : 'Updating seat availability…';
    document.querySelector('.seat-save-message')?.remove();
  }
  function saveCount() {
    const count = Number(input.value);
    if (!Number.isInteger(count) || count < 0 || count > capacity) return;
    saveQueue = saveQueue.then(async () => {
      const body = new FormData(form);
      body.set('occupied', String(count));
      body.set('ajax', '1');
      status.textContent = 'Updating seat availability…';
      const response = await fetch(seatEndpoint, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) throw new Error('Seat update failed. Please reload the driver dashboard and try again.');
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Seat availability could not be updated.');
      savedCount = result.occupied;
      document.querySelector('input[name="revision"]').value = result.revision;
      status.textContent = result.message;
      render();
    }).catch((error) => { status.textContent = error.message; });
  }
  function setCount(count, save = true) { input.value = count; render(); if (save) saveCount(); }
  form.addEventListener('submit', (event) => { event.preventDefault(); saveCount(); });
  input.addEventListener('input', render);
  input.addEventListener('change', saveCount);
  minus.addEventListener('click', () => setCount(Math.max(0, Number(input.value) - 1)));
  plus.addEventListener('click', () => setCount(Math.min(capacity, Number(input.value) + 1)));
  document.getElementById('seat-empty').addEventListener('click', () => setCount(0));
  document.getElementById('seat-full').addEventListener('click', () => setCount(capacity));
  minus.disabled = Number(input.value) === 0;
  plus.disabled = Number(input.value) === capacity;
})();
