import { fetchTerminals, boardingRouteSummary, googleMapsUrl, detailsUrl, element, locationIcon, translate } from './terminals-common.js?v=9';

const groups = document.getElementById('terminal-groups');
const resultCount = document.getElementById('terminal-result-count');
const error = document.getElementById('directory-error');
const empty = document.getElementById('directory-empty');
let records = [];
let loaded = false;
let loadFailure = null;
let refreshInProgress = false;

function card(record) {
  const article = element('article', 'terminal-card');
  const icon = element('span', 'terminal-location-icon');
  icon.append(locationIcon());
  const title = element('h4', '', record.name);
  const mapUrl = `user-map.html?terminal=${encodeURIComponent(record.id)}&focus=terminal`;
  const address = element('p', 'terminal-address', record.address);
  const link = element('a', 'terminal-details-link', translate('View Details'));
  link.href = detailsUrl(record.id);
  link.setAttribute('aria-label', translate('View details for {name}', { name: record.name }));
  const mapLink = element('a', 'terminal-map-link', translate('View on Map'));
  mapLink.href = mapUrl;
  const actions = element('div', 'terminal-card-actions');
  actions.append(link, mapLink);
  const googleUrl = googleMapsUrl(record);
  if (googleUrl) {
    const google = element('a', 'terminal-map-link', translate('Open in Google Maps'));
    google.href = googleUrl; google.target = '_blank'; google.rel = 'noopener noreferrer';
    actions.append(google);
  }
  article.append(icon, element('span', 'municipality-badge', record.municipality), title, address, boardingRouteSummary(record), actions);
  return article;
}

function render() {
  if (!loaded) {
    resultCount.textContent = translate(loadFailure ? 'Locations unavailable' : 'Loading locations…');
    if (loadFailure) {
      error.querySelector('p').textContent = translate(loadFailure);
    }
    return;
  }
  const filtered = records;
  groups.replaceChildren();
  for (const town of [...new Set(filtered.map((record) => record.municipality))]) {
    const section = element('section', 'terminal-group');
    const entries = filtered.filter((record) => record.municipality === town);
    const heading = element('h3', '', town === 'Mabini' ? 'Mabini, Batangas' : town);
    const grid = element('div', 'terminal-grid');
    grid.append(...entries.map(card));
    section.append(heading, grid);
    groups.append(section);
  }
  empty.hidden = filtered.length > 0;
  resultCount.textContent = translate('Showing {shown} of {total} locations', { shown: filtered.length, total: records.length });
  const url = new URL(location.href);
  url.searchParams.delete('q');
  url.searchParams.delete('municipality');
  history.replaceState(null, '', url);
}

async function load() {
  loaded = false;
  loadFailure = null;
  error.hidden = true;
  empty.hidden = true;
  groups.setAttribute('aria-busy', 'true');
  resultCount.textContent = translate('Loading locations…');
  try {
    const data = await fetchTerminals();
    records = data.terminals;
    loaded = true;
    render();
  } catch (failure) {
    loadFailure = failure.originalMessage || failure.message;
    error.hidden = false;
    render();
  } finally {
    groups.setAttribute('aria-busy', 'false');
  }
}

async function refreshLiveDrivers() {
  if (!loaded || refreshInProgress || document.hidden) return;
  refreshInProgress = true;
  try {
    const data = await fetchTerminals();
    records = data.terminals;
    render();
  } catch {
    // Keep the last successful terminal and driver data visible during a transient failure.
  } finally {
    refreshInProgress = false;
  }
}

document.getElementById('retry-directory').addEventListener('click', load);
document.addEventListener('passenger-preferences-changed', render);
load();
window.setInterval(refreshLiveDrivers, 3000);
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) refreshLiveDrivers();
});
