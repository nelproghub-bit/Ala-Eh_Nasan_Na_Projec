import { fetchTerminals, googleMapsUrl, verifiedCoordinates, activeDriversSummary, element, translate } from './terminals-common.js?v=11';
import { terminalMap } from './terminals-map.js?v=12';

const content = document.getElementById('terminal-content');
const error = document.getElementById('detail-error');
const loading = document.getElementById('detail-loading');
const retry = document.getElementById('retry-terminal');
const renderMap = terminalMap(document.getElementById('detail-map'), document.getElementById('detail-map-status'));
const id = new URLSearchParams(location.search).get('id');
let currentRecord = null;
let loadFailure = null;
let refreshInProgress = false;

function fact(label, value) {
  const wrapper = element('div');
  wrapper.append(element('dt', '', translate(label)), element('dd', '', value));
  return wrapper;
}

function render(record) {
  document.title = `${record.name} | Ala-Eh Nasaan Na?`;
  document.getElementById('terminal-title').textContent = record.name;
  document.getElementById('terminal-municipality').textContent = record.municipality;
  document.getElementById('terminal-address').textContent = record.address;
  document.getElementById('terminal-view-map').href = `user-map.html?terminal=${encodeURIComponent(record.id)}&focus=terminal&directions=1`;
  document.getElementById('open-google-maps')?.remove();
  const googleUrl = googleMapsUrl(record);
  if (googleUrl) {
    const google = element('a', 'terminal-action', translate('Open in Google Maps'));
    google.id = 'open-google-maps'; google.href = googleUrl;
    google.target = '_blank'; google.rel = 'noopener noreferrer';
    document.getElementById('terminal-actions').append(google);
  }
  document.getElementById('directions-note').hidden = Boolean(verifiedCoordinates(record));
  const facts = [
    ['Municipality', record.municipality],
    ['Address / landmark', record.address],
  ];
  document.getElementById('terminal-facts').replaceChildren(...facts.map(([label, value]) => fact(label, value)));
  document.getElementById('terminal-queue-list').replaceChildren(activeDriversSummary(record));
  content.hidden = false;
  renderMap([record]);
}

async function refreshActiveDrivers() {
  if (!currentRecord || refreshInProgress || document.hidden) return;
  refreshInProgress = true;
  try {
    const data = await fetchTerminals(id);
    currentRecord = data.terminal;
    document.getElementById('terminal-queue-list').replaceChildren(activeDriversSummary(currentRecord));
  } catch {
    // Keep the last successful driver list visible during a transient refresh failure.
  } finally {
    refreshInProgress = false;
  }
}

async function load() {
  error.hidden = true;
  loadFailure = null;
  loading.hidden = false;
  retry.hidden = false;
  if (!id || !/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) {
    loadFailure = 'Choose a location from the terminal directory to view its details.';
    error.querySelector('p').textContent = translate(loadFailure);
    error.hidden = false;
    retry.hidden = true;
    loading.hidden = true;
    return;
  }
  try {
    const data = await fetchTerminals(id);
    currentRecord = data.terminal;
    render(data.terminal);
  } catch (failure) {
    loadFailure = failure.originalMessage || failure.message;
    error.querySelector('p').textContent = translate(loadFailure);
    error.hidden = false;
    retry.hidden = failure.status === 404 || failure.status === 400;
  } finally {
    loading.hidden = true;
  }
}
retry.addEventListener('click', load);
window.setInterval(refreshActiveDrivers, 3000);
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) refreshActiveDrivers();
});
document.addEventListener('passenger-preferences-changed', () => {
  if (currentRecord) render(currentRecord);
  else if (loadFailure) error.querySelector('p').textContent = translate(loadFailure);
});
load();
