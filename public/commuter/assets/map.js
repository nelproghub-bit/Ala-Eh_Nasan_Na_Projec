import { fetchTerminals, filterTerminals, boardingRouteSummary, verifiedCoordinates, landmarkCoordinates, mapCoordinates, googleMapsUrl, directionsUrl, detailsUrl, element, translate } from './terminals-common.js?v=8';
import { terminalMap } from './terminals-map.js?v=12';
import { focusTerminal } from './map-selection.js?v=7';
import { routeClient, formatDistance, validPosition } from './routing.js?v=2';
import { closestTerminal, terminalProximity } from './nearest-terminal.js?v=3';
import { createJeepneyTracing, drawJeepneyTrace, fetchJeepneyTraces } from './jeepney-tracing.js?v=7';
import { createLocationTracker } from './location-tracking.js?v=2';
import { createRoutePreview } from './route-preview.js?v=3';

// General city reference, never used as a terminal pin.
const overview = [13.757211, 121.058111];
const overviewZoom = 13;
const entryParameters = new URLSearchParams(location.search);
const requestedId = Number(entryParameters.get('terminal'));
const requestedRouteId = Number(entryParameters.get('route'));
const requestedLocationKey = entryParameters.get('location') || '';
let selectedId = Number.isSafeInteger(requestedId) && requestedId > 0 ? requestedId : null;
const focusRequestedTerminal = selectedId !== null && entryParameters.get('focus') === 'terminal';
// A terminal detail page already supplies focus=terminal; treat that handoff as a directions request.
const directionsRequested = focusRequestedTerminal;
const get = id => document.getElementById(id);
const list = get('map-locations');
const count = get('map-result-count'), status = get('system-map-status'), locationStatus = get('user-location-status');
const locateButton = get('map-locate'), retryButton = get('retry-map');
const nearestStatus = get('nearest-terminal-status');
const routePanel = get('selected-terminal-panel'), estimateButton = get('estimate-route');
const locationPrompt = get('location-prompt');
const locationGate = get('location-access');
const enableLocation = get('location-access-enable');
let mapAccessGranted = false, locationGateError = null;
const terminalPanel = get('map-terminal-panel');
const searchButton = get('map-search'), searchInput = get('map-search-query');
const nearestSearchButton = get('map-search-nearest');
const requestRoute = routeClient();
let records = [], mapState = null, userMarker = null, userPosition = null;
let loadFailure = null, loading = true, locating = false, locationMessage = '', pendingLocation = null;
// Normal Map entry follows the user; a directory/details click explicitly focuses its terminal.
let focusSelection = focusRequestedTerminal, routeController = null, routeVersion = 0, routeLine = null;
let routeState = { kind: 'idle' };
let nearestId = null;
let nearestFinderId = null, nearestFinding = false, selectionVersion = 0;
let nearestFinderQuery = null, searchPanelRequested = false;
let nearestPin = null, nearestPinId = null;
let terminalPinRequested = selectedId !== null;
let routePreview = null, requestedRouteLayer = null, requestedRouteLoading = false, requestedRouteDisplayed = false;
let automaticLocationRequested = false;
let automaticDirectionsStarted = false;
let userAccuracyCircle = null, followingLocation = !focusRequestedTerminal, trackingState = 'paused', trackingMap = null;
const locationFreshness = 60000;
const locationAccuracyNotice = 100;
const secureLocationMessage = 'Location access requires a secure HTTPS connection. Open the system using a trusted HTTPS link.';
// Keep the previous default foot-path distance to the boarding point, with one action.
const distanceMode = 'walking';
const selectedRecord = () => records.find(record => record.id === selectedId);
const locationTracker = createLocationTracker({
  geolocation: navigator.geolocation, secure: globalThis.isSecureContext !== false,
  onPosition: updateUserPosition,
  onState(state) {
    trackingState = state; locating = state === 'locating';
    if (locating) locationGateError = null;
    updateTrackingIndicator(); updateLocationGate();
  },
  onError(error) {
    locationGateError = error.code;
    locationMessage = error.message;
    if (error.code === 1) {
      mapAccessGranted = false;
      userPosition = null; nearestId = null;
      clearRoute(); updateSelection();
      userMarker?.remove(); userMarker = null;
      userAccuracyCircle?.remove(); userAccuracyCircle = null;
      renderList();
    }
    locationStatus.textContent = translate(locationMessage);
    updateNearestResult();
    updateLocationPrompt();
    updateLocationGate();
  },
});
const tracing = createJeepneyTracing({
  getMapState: () => mapState,
  requestPreview: requestRoute,
  beforeShow: () => { clearRoute(); updateSelection(); },
  onReset: () => {
    terminalPinRequested = false;
    clearRoute(); selectedId = null; focusSelection = false;
    updateSelectionUrl(); render();
    mapState?.map.setView(overview, overviewZoom);
  },
});

const renderMap = terminalMap(get('system-map'), status, {
  overviewCenter: overview, overviewZoom, minZoom: 12, startWithOverview: true, preserveView: true, showPopups: false,
  showMarkers: true,
  onSelect: id => selectTerminal(id, false),
  onReady(state) {
    mapState = state;
    if (trackingMap !== state.map) {
      trackingMap = state.map;
      state.map.on('dragstart', stopFollowing);
    }
    locateButton.disabled = locating || loading || nearestFinding || Boolean(pendingLocation);
    retryButton.hidden = true;
    get('map-load-notice').hidden = true;
    updateSelection();
    tracing.setMapState(state);
    routePreview?.setMapState(state);
    showRequestedRoute();
    updateSearchControls();
    updateLocationGate();
    if (focusSelection && focusTerminal(state, selectedRecord(), mapViewPadding(), !terminalPinRequested)) focusSelection = false;
    if (!loading && !automaticLocationRequested) {
      automaticLocationRequested = true;
      if (!document.hidden) locationTracker.start();
    }
  },
  onError() { retryButton.hidden = false; get('map-load-notice').hidden = false; },
});

function updateLocationGate() {
  if (mapAccessGranted) {
    if (locationGate.open) {
      locationGate.close();
      get('system-map').focus({ preventScroll: true });
    }
    return;
  }
  const insecure = globalThis.isSecureContext === false;
  const unsupported = !navigator.geolocation?.watchPosition;
  const waiting = !insecure && !unsupported && !locationGateError && (locating || !mapState);
  locationGate.dataset.state = waiting ? 'locating' : 'blocked';
  get('location-jeepney-loader').hidden = false;
  get('location-access-title').textContent = waiting ? 'Finding your location...' : 'Location unavailable';
  get('location-access-help').hidden = true;
  enableLocation.hidden = waiting;
  const message = insecure ? secureLocationMessage
    : unsupported ? 'This browser does not support location sharing. Open the system in Safari or another browser with location support.'
      : waiting ? 'Choose Allow if your browser asks for your location.'
        : locationGateError === 1 ? "Location access is blocked. Allow Location for this website in Safari's website settings, and enable Location Services for Safari Websites in your iPhone settings. Then try again."
          : locationGateError ? 'Your location could not be determined. Check Location Services and your signal, then try again.'
            : 'Allow location access to position the map and find terminals near you.';
  get('location-access-message').textContent = message;
  enableLocation.disabled = insecure || unsupported || locating || !mapState;
  enableLocation.textContent = insecure ? 'HTTPS required' : locating ? 'Finding your location...' : 'Enable location';
  enableLocation.setAttribute('aria-busy', String(locating));
  if (!locationGate.open) locationGate.showModal();
}

function setTerminalPanel(open, moveFocus = false) {
  terminalPanel.hidden = !open;
  searchButton.setAttribute('aria-expanded', String(open));
  document.body.classList.toggle('map-panel-open', open);
  if (open) get('map-panel-body').scrollTop = 0;
  if (moveFocus) (open ? get('map-panel-close') : searchPanelRequested ? searchButton : get('system-map')).focus({ preventScroll: true });
  mapState?.map.invalidateSize({ pan: false, animate: false });
}

function mapViewPadding() {
  const viewport = get('system-map').getBoundingClientRect?.();
  const panel = !terminalPanel.hidden && terminalPanel.getBoundingClientRect?.();
  if (!viewport) return { padding: [24, 24] };
  if (!panel) return { paddingTopLeft: [20, 80], paddingBottomRight: [20, 108] };
  return panel.width > viewport.width * .7
    ? { paddingTopLeft: [20, 80], paddingBottomRight: [20, Math.max(24, viewport.bottom - panel.top + 12)] }
    : { paddingTopLeft: [panel.width + 28, 80], paddingBottomRight: [20, 24] };
}

function clearRoute() {
  routeVersion++;
  routeController?.abort();
  routeController = null;
  routeLine?.remove();
  routeLine = null;
  routeState = { kind: 'idle' };
  mapState?.map.stop?.();
  if (mapState?.map.getZoom?.() < 12) mapState.map.setZoom(12, { animate: false });
  mapState?.map.setMinZoom?.(12);
}

async function showRequestedRoute() {
  if (!Number.isSafeInteger(requestedRouteId) || requestedRouteId <= 0 || requestedRouteDisplayed
    || requestedRouteLoading || !mapState || loading || !records.length) return;
  requestedRouteLoading = true;
  try {
    const driver = selectedRecord()?.drivers?.find(item => Number(item.route_id) === requestedRouteId
      && (!requestedLocationKey || item.location_key === requestedLocationKey));
    let route = null;
    if (driver) {
      const origin = selectedRecord();
      const coordinates = mapCoordinates(origin);
      const routeKey = driver.route_key || ({ 10755762: '12000001', 10755763: '12000002' })[driver.route_id];
      const journey = await window.JeepneyRouting.loadAssignedRoute(routeKey, {
        locationKey: driver.location_key, name: origin.name,
        coordinates: coordinates ? [coordinates[1], coordinates[0]] : null,
      }, new URL('.', get('driver-routing-script').src));
      route = {
        id: requestedRouteId, name: journey.route.name, status: 'verified',
        geometry: { type: 'LineString', coordinates: journey.coordinates }, stops: [],
        isGeneralRoute: journey.isGeneralRoute,
        destination: String(routeKey) === '12000002' ? 'Mabini' : 'Batangas City',
      };
    } else if (requestedLocationKey) {
      throw new Error('The selected driver assignment is no longer available');
    } else {
      const routes = await fetchJeepneyTraces();
      route = routes.find(item => item.id === requestedRouteId);
    }
    const routeNames = String(route?.name || '').split(/\s*(?:\u2192|->)\s*/).map(name => name.trim()).filter(Boolean);
    const start = selectedRecord() || records.find(record => record.id === route?.start_terminal_id) || { name: routeNames[0] || 'Starting point' };
    const end = records.find(record => record.id === route?.end_terminal_id) || { name: routeNames.at(-1) || 'Destination' };
    if (!route || !start || !end) throw new Error('Requested route unavailable');
    tracing.clear();
    routePreview?.clear();
    requestedRouteLayer?.remove();
    requestedRouteLayer = drawJeepneyTrace(mapState, route,
      route.isGeneralRoute ? { name: 'General route start' } : start,
      route.destination ? { name: route.destination } : end, { endpointsOnly: true });
    requestedRouteDisplayed = true;
    // The jeepney marks this route's origin; remove the overlapping selection pin.
    terminalPinRequested = false;
    clearNearestPin();
    setTerminalPanel(false);
    status.textContent = translate('Showing route: {name}', { name: route.name });
  } catch {
    status.textContent = translate('The requested jeepney route is temporarily unavailable.');
    get('map-load-notice').hidden = false;
  } finally {
    requestedRouteLoading = false;
  }
}

function hasConfirmedLocation() {
  return userPosition && validPosition(userPosition.coordinates) && Date.now() - userPosition.time < locationFreshness;
}

function estimatedDistance(record) {
  return record && hasConfirmedLocation() && routeState.kind === 'ready' && routeState.terminalId === record.id
    && Date.now() - routeState.originTime < locationFreshness && Number.isFinite(routeState.result.distance)
    ? routeState.result.distance : null;
}

function updateLocationPrompt() {
  if (locationPrompt.hidden) return;
  let message = 'Your location has not been confirmed yet. Use My location on the map and allow location access to find nearby terminals or estimate your trip.';
  if (globalThis.isSecureContext === false) message = secureLocationMessage;
  else if (!navigator.geolocation) message = 'This browser does not support location sharing. Use a browser with location support to estimate your trip.';
  else if (locating) message = 'Confirming your location. Please allow location access in your browser. You can retry My location if the request fails.';
  else if (locationGateError === 1) message = 'Location access is blocked. Allow location access for this site in your browser, then click My location.';
  else if (userPosition) message = 'Your location needs to be refreshed. Click My location to update nearby terminals and trip estimates.';
  get('location-prompt-message').textContent = message;
}

function showLocationPrompt() {
  clearRoute(); updateSelection();
  locationPrompt.hidden = false;
  updateLocationPrompt();
}

function updateSelectionUrl() {
  const url = new URL(location.href);
  if (selectedId) url.searchParams.set('terminal', selectedId);
  else url.searchParams.delete('terminal');
  history.replaceState(null, '', url);
}

function selectTerminal(id, focus = true, focusMap = true, openPanel = true) {
  if (!records.some(record => record.id === id)) return;
  routePreview?.clear();
  mapState?.map.closePopup?.();
  terminalPinRequested = true;
  clearNearestPin();
  selectionVersion++;
  nearestFinderId = null;
  nearestFinderQuery = null;
  stopFollowing();
  tracing.clear();
  if (selectedId !== id) clearRoute();
  selectedId = id;
  tracing.setStartTerminal(id);
  focusSelection = focus;
  updateSelectionUrl();
  renderList();
  updateSelection();
  if (openPanel) setTerminalPanel(true);
  if (focus) get(`map-select-${id}`)?.focus({ preventScroll: true });
  if (focusMap && focusTerminal(mapState, selectedRecord(), mapViewPadding(), false)) focusSelection = false;
}

function locationCard(record, closestMatchId) {
  const selected = record.id === selectedId;
  const card = element('article', `map-location-card${selected ? ' is-selected' : ''}`);
  const hasPin = Boolean(verifiedCoordinates(record));
  const hasLandmark = Boolean(landmarkCoordinates(record));
  const choose = element('button', 'map-select-terminal');
  choose.id = `map-select-${record.id}`;
  choose.type = 'button';
  choose.setAttribute('aria-pressed', String(selected));
  choose.setAttribute('aria-controls', 'selected-terminal-panel');
  choose.setAttribute('aria-label', translate('Select {name}', { name: record.name }));
  choose.append(element('span', 'municipality-badge', record.municipality), element('span', 'map-card-name', record.name), element('span', 'map-card-address', record.address));
  if (hasConfirmedLocation() && record.id === nearestId) choose.append(element('span', 'map-nearest-label', translate('Closest mapped location')));
  else if (record.id === closestMatchId) choose.append(element('span', 'map-nearest-label', 'Closest match'));
  const distance = estimatedDistance(record);
  if (distance !== null) choose.append(element('span', 'boarding-distance', translate('Estimated path distance: {distance}', { distance: formatDistance(distance) })));
  choose.append(element('span', `map-pin-state${hasPin ? ' is-verified' : ''}`, translate(hasPin ? 'Verified map pin' : hasLandmark ? 'Landmark reference only. Exact boarding point pending verification.' : 'Exact boarding point pending verification.')));
  choose.addEventListener('click', () => selectTerminal(record.id));
  const actions = element('div', 'map-location-actions');
  const details = element('a', '', translate('View Details'));
  details.href = detailsUrl(record.id);
  details.setAttribute('aria-label', translate('View details for {name}', { name: record.name }));
  actions.append(details);
  const mapsUrl = googleMapsUrl(record);
  if (mapsUrl) {
    const google = element('a', '', translate('Open in Google Maps'));
    google.href = mapsUrl; google.target = '_blank'; google.rel = 'noopener noreferrer';
    actions.append(google);
  }
  card.append(choose, boardingRouteSummary(record), actions);
  card.addEventListener('click', event => {
    if (!event.target.closest('a, button')) selectTerminal(record.id);
  });
  return card;
}

function renderList() {
  const scrollTop = list.scrollTop;
  const focusedId = document.activeElement?.id;
  const matches = filterTerminals(records, searchInput.value);
  const closestMatchId = hasConfirmedLocation() ? closestTerminal(matches, userPosition.coordinates)?.id : null;
  const filtered = [...matches].sort((a, b) => {
    if (hasConfirmedLocation()) {
      const difference = (terminalProximity(a, userPosition.coordinates) ?? Infinity) - (terminalProximity(b, userPosition.coordinates) ?? Infinity);
      if (!Number.isNaN(difference) && difference) return difference;
    }
    return 0;
  });
  updateSearchControls(matches);
  list.replaceChildren();
  if (loading) count.textContent = translate('Loading locations...');
  else if (loadFailure) {
    count.textContent = translate('Locations unavailable');
    const notice = element('div', 'terminal-notice');
    const retry = element('button', 'terminal-reset', translate('Try again'));
    retry.type = 'button'; retry.addEventListener('click', load);
    notice.append(element('p', '', translate(loadFailure)), retry); list.append(notice);
  } else {
    count.textContent = translate('Showing {shown} of {total} locations', { shown: filtered.length, total: records.length });
    if (!filtered.length) list.append(element('p', 'terminal-notice', searchInput.value.trim()
      ? 'No terminals match your search. Try another name or area, or clear your search.' : translate('No boarding locations are available yet.')));
    else list.append(...filtered.map(record => locationCard(record, closestMatchId)));
  }
  list.scrollTop = scrollTop;
  if (focusedId?.startsWith('map-select-')) get(focusedId)?.focus({ preventScroll: true });
  return filtered;
}

function updateSearchControls(matches = filterTerminals(records, searchInput.value)) {
  get('map-search-clear').hidden = !searchInput.value;
  nearestSearchButton.disabled = !mapState || loading || Boolean(loadFailure) || nearestFinding || !matches.some(record => mapCoordinates(record));
  nearestSearchButton.setAttribute('aria-busy', String(nearestFinding));
  nearestSearchButton.textContent = nearestFinding ? 'Finding closest terminal...' : 'Closest to me';
}

function openTerminalSearch() {
  searchPanelRequested = true;
  selectionVersion++;
  routePreview?.clear(); stopFollowing(); tracing.clear(); clearRoute();
  selectedId = null; nearestFinderId = null; nearestFinderQuery = null;
  terminalPinRequested = false; focusSelection = false;
  clearNearestPin(); updateSelectionUrl(); renderList(); updateSelection();
  setTerminalPanel(true);
  searchInput.focus({ preventScroll: true });
}

function searchChanged() {
  selectionVersion++; nearestFinderId = null; nearestFinderQuery = null;
  clearRoute(); selectedId = null; terminalPinRequested = false; focusSelection = false;
  clearNearestPin(); updateSelectionUrl(); renderList(); updateSelection();
  list.scrollTop = 0;
}

function closeTerminalPanel() {
  selectionVersion++;
  setTerminalPanel(false, true);
}

function updateSelection() {
  const record = selectedRecord();
  updateNearestResult();
  document.body.classList.toggle('map-terminal-selected', Boolean(record));
  get('map-selection-nearest').hidden = !record || record.id !== nearestId;
  routePanel.hidden = !record;
  get('map-selection-status').hidden = !record;
  if (!record) return;
  const hasPin = Boolean(verifiedCoordinates(record));
  const hasLandmark = Boolean(landmarkCoordinates(record));
  const hasDestination = Boolean(mapCoordinates(record));
  const usesLandmark = !hasPin && hasLandmark;
  get('map-selection-name').textContent = record.name;
  get('map-selection-note').textContent = translate(hasPin ? 'Selected terminal location' : hasLandmark ? 'Landmark reference only. Exact boarding point pending verification.' : 'Exact boarding point pending verification.');
  get('route-terminal-name').textContent = record.name;
  get('route-terminal-address').textContent = record.address;
  get('selected-boarding-routes').replaceChildren(boardingRouteSummary(record));
  const coordinateNote = usesLandmark ? 'Distance is estimated to the mapped landmark.' : record.coordinate_note || '';
  get('route-coordinate-note').textContent = translate(coordinateNote);
  get('route-coordinate-note').hidden = !coordinateNote;
  estimateButton.disabled = !hasDestination || !mapState || loading || routeState.kind === 'loading';
  get('route-privacy').hidden = !hasDestination;
  const distance = estimatedDistance(record);
  get('route-result').hidden = !hasDestination || distance === null;
  get('route-distance-label').textContent = usesLandmark ? 'Estimated distance to landmark' : 'Estimated distance';
  const directions = get('route-directions');
  const directionUrl = directionsUrl(record);
  directions.hidden = !directionUrl;
  if (directionUrl) {
    const url = new URL(directionUrl);
    url.searchParams.set('travelmode', distanceMode);
    directions.href = url.href;
  }
  const mapsUrl = googleMapsUrl(record);
  get('route-google-maps').hidden = !mapsUrl;
  if (mapsUrl) get('route-google-maps').href = mapsUrl;
  let message = usesLandmark ? 'Estimate the distance from your location to this landmark.' : 'Estimate the distance from your location to this terminal.';
  if (!hasDestination) message = 'Map location not yet available.';
  else if (routeState.kind === 'loading') message = 'Calculating route...';
  else if (routeState.kind === 'error') message = routeState.message;
  else if (routeState.kind === 'ready') message = distance === null ? 'Refresh My location and estimate the distance again.'
    : usesLandmark ? 'Estimated distance to the mapped landmark.' : 'Estimated distance to the terminal.';
  get('route-status').textContent = translate(message);
  if (distance !== null) {
    get('route-distance').textContent = formatDistance(distance);
  }
  mapState?.map.invalidateSize({ pan: false, animate: false });
}

function render() {
  renderList();
  locationStatus.textContent = locationMessage ? translate(locationMessage) : '';
  updateSelection();
  renderMap(records);
}

async function load() {
  loading = true; loadFailure = null; render();
  try {
    records = (await fetchTerminals()).terminals;
    nearestId = closestTerminal(records, userPosition?.coordinates)?.id ?? null;
    tracing.setTerminals(records, selectedId);
    showRequestedRoute();
    if (selectedRecord()) setTerminalPanel(!Number.isSafeInteger(requestedRouteId));
  }
  catch (error) { loadFailure = error.originalMessage || error.message; }
  finally {
    if (focusRequestedTerminal && (!selectedRecord() || !mapCoordinates(selectedRecord()))) {
      followingLocation = true; focusSelection = false;
    }
    loading = false; render();
  }
}

function stopFollowing() {
  followingLocation = false;
  updateTrackingIndicator();
}

function updateTrackingIndicator() {
  const indicator = get('map-location-indicator');
  const messages = {
    locating: 'Finding your location...',
    tracking: followingLocation ? 'Following your location' : 'Live location',
    paused: 'Location updates paused',
    blocked: 'Location access unavailable',
    unavailable: 'Waiting for location signal...',
  };
  get('map-location-label').textContent = messages[trackingState];
  indicator.dataset.state = trackingState;
  locateButton.setAttribute('aria-pressed', String(followingLocation));
  locateButton.disabled = !mapState || loading || Boolean(pendingLocation) || nearestFinding;
  locateButton.setAttribute('aria-busy', String(nearestFinding));
  updateSearchControls();
  userMarker?.getElement()?.classList.toggle('is-paused', trackingState !== 'tracking');
  if (trackingState === 'locating') locationStatus.textContent = messages.locating;
  updateLocationPrompt();
}

function centerOnUser(firstFix = false) {
  if (!mapState || !userPosition) return;
  mapState.map.stop();
  mapState.map.fitBounds([userPosition.coordinates, userPosition.coordinates], {
    ...mapViewPadding(), maxZoom: firstFix ? 16 : mapState.map.getZoom(), animate: !firstFix,
  });
}

function updateUserPosition(fix) {
  if (!mapState) return;
  const firstFix = !userPosition;
  userPosition = fix;
  mapAccessGranted = true;
  locationGateError = null;
  updateLocationGate();
  // Update existing layers in place; no invented movement or saved location history.
  if (!userMarker) {
    userMarker = mapState.leaflet.marker(fix.coordinates, {
      icon: mapState.leaflet.divIcon({ className: 'user-location-marker', html: '<span class="user-location-pulse"></span><span class="user-location-dot"></span>', iconSize: [24, 24], iconAnchor: [12, 12] }),
      title: 'Your current location', zIndexOffset: 1000,
    }).addTo(mapState.map).bindPopup(element('strong', '', translate('Your location')));
  } else userMarker.setLatLng(fix.coordinates);
  if (fix.accuracy !== null) {
    if (!userAccuracyCircle) userAccuracyCircle = mapState.leaflet.circle(fix.coordinates, {
      radius: fix.accuracy, color: '#2563eb', weight: 1, opacity: .25, fillColor: '#3b82f6', fillOpacity: .1, interactive: false,
    }).addTo(mapState.map);
    else userAccuracyCircle.setLatLng(fix.coordinates).setRadius(fix.accuracy);
  } else { userAccuracyCircle?.remove(); userAccuracyCircle = null; }
  nearestId = closestTerminal(records, fix.coordinates)?.id ?? null;
  renderList(); updateNearestResult();
  locationMessage = fix.accuracy > locationAccuracyNotice
    ? `Location found (approximately ${Math.round(fix.accuracy)} m accuracy). You can estimate your trip or use My location again to improve accuracy.`
    : `Your live location is shown in blue.${fix.accuracy !== null ? ' The circle shows reported accuracy.' : ''} It is not saved.`;
  locationStatus.textContent = translate(locationMessage);
  locationPrompt.hidden = true;
  if (followingLocation) centerOnUser(firstFix);
  if (directionsRequested && !automaticDirectionsStarted && !loading && selectedRecord() && mapCoordinates(selectedRecord())) {
    automaticDirectionsStarted = true;
    followingLocation = false;
    setTerminalPanel(true);
    // The driving line begins only after the browser supplies a real, permissioned location fix.
    window.setTimeout(() => estimateRoute({ drawPath: true, mode: 'driving' }), 0);
  }
}

function locateUser() {
  if (pendingLocation) return pendingLocation;
  pendingLocation = locationTracker.locate().finally(() => {
    pendingLocation = null; updateTrackingIndicator(); updateSelection();
  });
  updateTrackingIndicator();
  return pendingLocation;
}

async function goToCurrentLocation() {
  if (pendingLocation) return;
  try {
    await locateUser();
    // This control follows the user's position only. It deliberately does not
    // select a terminal, calculate a route, or open the terminal search panel.
    followingLocation = true;
    centerOnUser(true);
  } catch (error) {
    if (error.name !== 'AbortError') showLocationPrompt();
  } finally {
    updateTrackingIndicator();
  }
}

function clearNearestPin() {
  nearestPin?.remove(); nearestPin = null;
  mapState?.markers?.get(nearestPinId)?.setStyle?.({ opacity: 1, fillOpacity: 1 });
  nearestPinId = null;
}

function showNearestPin(record, label) {
  const coordinates = mapCoordinates(record);
  if (!mapState || !coordinates) { clearNearestPin(); return; }
  if (!nearestPin || nearestPinId !== record.id) {
    clearNearestPin();
    nearestPinId = record.id;
    nearestPin = mapState.leaflet.marker(coordinates, {
      icon: mapState.leaflet.divIcon({
        className: 'nearest-terminal-pin',
        html: '<svg viewBox="0 0 40 54" width="40" height="54" aria-hidden="true"><path d="M20 1C9.5 1 1 9.5 1 20c0 14.2 19 33 19 33s19-18.8 19-33C39 9.5 30.5 1 20 1Z" fill="#dc2626" stroke="#fff" stroke-width="2"/><circle cx="20" cy="20" r="7" fill="#fff"/></svg>',
        iconSize: [40, 54], iconAnchor: [20, 54], popupAnchor: [0, -48],
      }),
      title: `${label}: ${record.name}`, zIndexOffset: 900,
    }).addTo(mapState.map).on('click', () => window.location.assign(detailsUrl(record.id)));
  } else nearestPin.setLatLng(coordinates);
  nearestPin.options.title = `${label}: ${record.name}`;
  const icon = nearestPin.getElement();
  icon?.setAttribute('aria-label', `${label}: ${record.name}`);
  icon?.setAttribute('title', `${label}: ${record.name}`);
  // Replace its small circle visually while retaining the original marker and popup.
  mapState.markers?.get(record.id)?.setStyle?.({ opacity: 0, fillOpacity: 0 });
}

function updateNearestResult() {
  const record = selectedRecord();
  const candidates = nearestFinderQuery === null ? records : filterTerminals(records, nearestFinderQuery);
  const closestId = hasConfirmedLocation() ? closestTerminal(candidates, userPosition.coordinates)?.id : null;
  const closestLabel = nearestFinderQuery?.trim() && record?.id !== nearestId ? 'Closest matching terminal' : 'Closest terminal to you';
  if (terminalPinRequested && record) {
    const pinLabel = nearestFinderId === record.id && record.id === closestId
      ? closestLabel : 'Selected terminal';
    showNearestPin(record, pinLabel);
  } else clearNearestPin();
  if (nearestFinderId === null || !record) { nearestStatus.hidden = true; return; }
  const distance = estimatedDistance(record);
  nearestStatus.hidden = false;
  const label = record.id === closestId ? closestLabel : 'Selected terminal';
  const estimate = !hasConfirmedLocation() ? 'Refresh My location for distance'
    : distance !== null ? `Est. path distance: ${formatDistance(distance)}`
      : routeState.kind === 'error' ? 'Distance unavailable'
        : routeState.kind === 'ready' ? 'Refresh My location for distance' : 'Estimating distance...';
  nearestStatus.textContent = translate('{label}  -  {name}  -  {estimate}', { label, name: record.name, estimate });
}

async function locateNearestTerminal({ keepPanelOpen = false } = {}) {
  if (nearestFinding) return;
  const query = keepPanelOpen ? searchInput.value : null;
  const candidates = query === null ? records : filterTerminals(records, query);
  if (keepPanelOpen && !candidates.some(record => mapCoordinates(record))) return;
  routePreview?.clear();
  const requestedSelection = selectionVersion;
  nearestFinding = true; stopFollowing(); updateTrackingIndicator();
  tracing.clear();
  clearRoute();
  try {
    const origin = await locateUser();
    if (requestedSelection !== selectionVersion) return;
    followingLocation = true;
    const nearest = closestTerminal(candidates, origin.coordinates);
    if (!nearest) {
      locationMessage = 'Your live location is shown in blue. No verified map references are available to find the closest location.';
      locationStatus.textContent = translate(locationMessage);
      centerOnUser(true); updateTrackingIndicator();
      setTerminalPanel(keepPanelOpen);
      nearestStatus.textContent = 'No mapped boarding points are available yet.';
      nearestStatus.hidden = false;
      return;
    }
    setTerminalPanel(keepPanelOpen);
    selectTerminal(nearest.id, false, false, keepPanelOpen);
    renderList();
    await renderMap(records);
    if (selectedId !== nearest.id) return;
    nearestFinderId = nearest.id;
    nearestFinderQuery = query;
    followingLocation = false;
    updateNearestResult();
    const distanceTask = estimateRoute({ drawPath: false });
    mapState.map.setMinZoom?.(8);
    mapState.map.fitBounds(mapState.leaflet.latLngBounds([origin.coordinates, mapCoordinates(nearest)]), {
      ...mapViewPadding(), maxZoom: 16, animate: false,
    });
    updateTrackingIndicator();
    await distanceTask;
  } catch (error) { if (error.name !== 'AbortError') showLocationPrompt(); }
  finally { nearestFinding = false; updateTrackingIndicator(); }
}

async function estimateRoute({ drawPath = true, mode = distanceMode } = {}) {
  const record = selectedRecord();
  const destination = record && mapCoordinates(record);
  if (!destination || !mapState) return;
  if (!hasConfirmedLocation()) { showLocationPrompt(); return; }
  routePreview?.clear();
  stopFollowing();
  const origin = userPosition;
  tracing.clear();
  clearRoute();
  const current = routeVersion;
  routeState = { kind: 'loading' }; updateSelection();
  try {
    if (current !== routeVersion) return;
    routeController = new AbortController();
    const result = await requestRoute(origin.coordinates, destination, mode, routeController.signal);
    if (current !== routeVersion) return;
    if (drawPath) {
      const stroke = { opacity: 1, dashArray: null, lineCap: 'round', lineJoin: 'round', smoothFactor: .8 };
      const outline = mapState.leaflet.geoJSON(result.geometry, { interactive: false, style: { ...stroke, color: '#ffffff', weight: 8 } });
      const path = mapState.leaflet.geoJSON(result.geometry, { interactive: false, style: { ...stroke, color: '#7c3aed', weight: 5 } });
      routeLine = mapState.leaflet.featureGroup([outline, path]).addTo(mapState.map);
      // Keep the colored stroke above its outline and both below location markers.
      path.bringToBack();
      outline.bringToBack();
      mapState.map.setMinZoom?.(8);
      mapState.map.fitBounds(routeLine.getBounds(), { ...mapViewPadding(), maxZoom: 17 });
    }
    routeState = { kind: 'ready', result, terminalId: record.id, originTime: origin.time };
  } catch (error) {
    if (current !== routeVersion || error.name === 'AbortError') return;
    routeState = { kind: 'error', message: error.code === 'no-route' ? 'No mapped path was found to this location.'
      : error.code === 'off-road' ? 'Your location or the destination is too far from a mapped path. Refresh My location or check Get Directions.'
      : error.code === 'busy' ? 'The routing service is busy. Please try again shortly.'
      : 'Route estimates are temporarily unavailable. Please try again.' };
  } finally { if (current === routeVersion) { routeController = null; renderList(); updateSelection(); } }
}


retryButton.addEventListener('click', () => { mapState?.retryTiles(); render(); });
searchButton.addEventListener('click', openTerminalSearch);
searchInput.addEventListener('input', searchChanged);
get('map-search-form').addEventListener('submit', event => event.preventDefault());
get('map-search-clear').addEventListener('click', () => { searchInput.value = ''; searchChanged(); searchInput.focus({ preventScroll: true }); });
nearestSearchButton.addEventListener('click', () => locateNearestTerminal({ keepPanelOpen: true }));
locateButton.addEventListener('click', goToCurrentLocation);
estimateButton.addEventListener('click', estimateRoute);
get('location-prompt-dismiss').addEventListener('click', () => { locationPrompt.hidden = true; });
locationGate.addEventListener('cancel', event => event.preventDefault());
locationGate.addEventListener('keydown', event => { if (event.key === 'Escape') event.preventDefault(); });
// Older browsers may still close an automatically opened dialog without a cancellable event.
locationGate.addEventListener('close', () => { if (!mapAccessGranted) updateLocationGate(); });
enableLocation.addEventListener('click', () => {
  if (enableLocation.disabled) return;
  locationGateError = null;
  // Retry explicitly; do not run repeated permission prompts after a denial.
  locationTracker.stop();
  automaticLocationRequested = true;
  locationTracker.start();
});
get('map-panel-close').addEventListener('click', closeTerminalPanel);
document.addEventListener('keydown', event => {
  if (event.defaultPrevented || document.body.classList.contains('navigation-open') || document.querySelector('dialog[open]')) return;
  if (event.key === 'Escape' && !terminalPanel.hidden) { event.preventDefault(); closeTerminalPanel(); }
});
document.addEventListener('passenger-preferences-changed', () => {
  userMarker?.setPopupContent(element('strong', '', translate('Your location')));
  render();
});
routePreview = createRoutePreview({
  enabled: false, // Temporarily hide the route card while retaining the saved preview.
  beforeShow() {
    tracing.clear(); clearRoute(); stopFollowing();
    selectedId = null; nearestFinderId = null; terminalPinRequested = false; focusSelection = false;
    clearNearestPin(); updateSelectionUrl(); updateSelection(); setTerminalPanel(false);
  },
});
setTerminalPanel(false);
const mapContainer = get('system-map');
mapContainer.addEventListener('wheel', stopFollowing, { passive: true });
mapContainer.addEventListener('touchstart', event => { if (event.touches.length > 1) stopFollowing(); }, { passive: true });
mapContainer.addEventListener('keydown', event => { if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', '+', '-'].includes(event.key)) stopFollowing(); });
mapContainer.addEventListener('click', event => { if (event.target.closest('.leaflet-control-zoom')) stopFollowing(); });
document.addEventListener('visibilitychange', () => {
  if (document.hidden) locationTracker.stop();
  else if (mapState && automaticLocationRequested) locationTracker.start();
});
window.addEventListener('pagehide', () => locationTracker.stop());
window.addEventListener('pageshow', event => { if (event.persisted && mapState && !document.hidden) locationTracker.start(); });
updateLocationGate();
load();
