(() => {
  'use strict';
  const root = document.getElementById('live-route');
  if (!root) return;
  const get = (id) => document.getElementById(id);
  const service = get('live-route-service');
  const hint = get('live-next-hint');
  const start = get('live-route-start');
  const stop = get('live-route-stop');
  const status = get('live-route-status');
  const badge = get('live-route-badge');
  let map, network, positionMarker, accuracyCircle, nextMarker, routes = [], route = null;
  let watch = null, generation = 0, progress = null, lastFix = null, lastGoodTime = 0, follow = true, staleTimer = null;
  const distanceText = (meters) => meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
  function message(text, state = 'unset', badgeText = 'GPS off') {
    status.textContent = text;
    badge.textContent = badgeText;
    badge.className = `trip-badge trip-${state}`;
  }
  function clearGuidance(title, description) {
    get('live-next-name').textContent = title;
    get('live-next-description').textContent = description;
    get('live-next-distance').textContent = '—';
    get('live-current-road').textContent = 'Current location: awaiting a reliable GPS match';
    get('live-upcoming').replaceChildren();
    if (nextMarker) { map.removeLayer(nextMarker); nextMarker = null; }
  }
  function endTracking(announce = true) {
    generation++;
    if (watch !== null) navigator.geolocation.clearWatch(watch);
    watch = null;
    clearInterval(staleTimer); staleTimer = null;
    progress = null; lastFix = null; lastGoodTime = 0;
    start.disabled = !route; stop.disabled = true;
    get('live-route-follow').disabled = true;
    if (positionMarker) { map.removeLayer(positionMarker); positionMarker = null; }
    if (accuracyCircle) { map.removeLayer(accuracyCircle); accuracyCircle = null; }
    if (announce) {
      clearGuidance('Tracking stopped', 'Start live tracking when you are ready to continue.');
      get('live-gps-quality').textContent = 'GPS tracking is off.';
      message('Tracking stopped. Your location is no longer being watched.');
    }
  }
  function showRoute() {
    endTracking(false);
    route = routes.find((item) => String(item.id) === service.value) || null;
    network.clearLayers();
    hint.replaceChildren(new Option('Detect automatically', ''));
    clearGuidance('Waiting for GPS', 'Start live tracking to see your next stop.');
    get('live-gps-quality').textContent = 'No location received yet.';
    start.disabled = !route; hint.disabled = !route;
    if (!route) return;
    L.polyline(route.coordinates.map(([lng, lat]) => [lat, lng]), { color: '#2563eb', weight: 5, opacity: .7 }).addTo(network);
    (route.stops || []).forEach((point) => {
      hint.add(new Option(point.name, point.id));
      const marker = L.circleMarker([point.coordinates[1], point.coordinates[0]], { radius: 5, color: '#087f72', weight: 2, fillColor: 'white', fillOpacity: 1 });
      const label = document.createElement('span'); label.textContent = point.name; marker.bindTooltip(label).addTo(network);
    });
    if (map.getSize().x) map.fitBounds(network.getBounds(), { padding: [30, 30] });
    message('Route selected. Start live tracking and allow location access.');
  }
  function acceptPosition(position, token) {
    if (token !== generation || !route) return;
    if (!Number.isFinite(position.timestamp) || Date.now() - position.timestamp > 30000 || position.timestamp > Date.now() + 10000) {
      clearGuidance('Waiting for fresh GPS', 'The device returned an old location. Keep GPS enabled.');
      message('Location is outdated. Waiting for a fresh GPS reading.', 'out', 'GPS stale');
      return;
    }
    if (lastFix && position.timestamp <= lastFix.timestamp) return;
    const c = position.coords;
    const fix = { coordinates: [c.longitude, c.latitude], accuracy: c.accuracy, heading: c.heading, speed: c.speed, timestamp: position.timestamp };
    lastFix = fix;
    const current = LiveNavigation.locate(route, fix, progress, hint.value);
    get('live-gps-quality').textContent = `GPS accuracy: ±${Math.round(c.accuracy)} m  -  Updated ${new Date(position.timestamp).toLocaleTimeString()}`;
    if (current.state !== 'ready') {
      const messages = {
        invalid: 'The device returned an invalid location. Waiting for another reading.',
        weak: 'GPS accuracy is low. Waiting for a clearer signal before updating stops.',
        off_route: 'You are away from this mapped route. Check the selected route and direction.',
        ambiguous: 'Confirm the next stop you are heading toward below so GPS can identify the correct direction.',
        wrong_hint: 'Your selected next stop does not match this location. Check the next-stop selection.',
        wrong_direction: 'You appear to be traveling in the opposite direction. Select the correct route or next stop.',
        reacquire: 'Confirm your next stop to resume route matching after a change in direction or location.',
      };
      clearGuidance('Next stop unconfirmed', messages[current.state] || messages.invalid);
      message(messages[current.state] || messages.invalid, 'out', 'Check GPS / route');
      if (current.state === 'reacquire') progress = null;
      if (positionMarker) positionMarker.setOpacity(.35);
      return;
    }
    progress = current.progress;
    lastGoodTime = position.timestamp;
    const latlng = [c.latitude, c.longitude];
    if (!positionMarker) positionMarker = L.marker(latlng, { icon: L.divIcon({ className: 'live-driver-pin', html: '<img class="jeepney-marker-image" src="assets/jeepney-map-marker.png" alt="">', iconSize: [64, 36], iconAnchor: [32, 18] }), title: 'Your live position', zIndexOffset: 1000 }).addTo(map);
    else positionMarker.setLatLng(latlng).setOpacity(1);
    if (!accuracyCircle) accuracyCircle = L.circle(latlng, { radius: c.accuracy, color: '#2563eb', weight: 1, fillOpacity: .08 }).addTo(map);
    else accuracyCircle.setLatLng(latlng).setRadius(c.accuracy);
    if (follow) map.setView(latlng, Math.max(15, map.getZoom()), { animate: false });
    const next = current.upcoming[0];
    get('live-current-road').textContent = current.atStop ? `At stop: ${current.atStop.name}` : `Current road: ${current.road}`;
    get('live-next-name').textContent = next?.name || (current.finished ? 'End of mapped route' : 'No more recorded stops ahead');
    get('live-next-distance').textContent = next ? distanceText(next.distance) : '—';
    get('live-next-description').textContent = next ? `Along your route  -  ${next.access || 'Mapped stop'}` : 'Select your next service route when you start another trip.';
    get('live-upcoming').replaceChildren();
    current.upcoming.slice(0, 4).forEach((point) => {
      const item = document.createElement('li'); const name = document.createElement('strong'); name.textContent = point.name;
      const distance = document.createElement('span'); distance.textContent = distanceText(point.distance);
      item.append(name, distance); get('live-upcoming').append(item);
    });
    if (nextMarker) { map.removeLayer(nextMarker); nextMarker = null; }
    if (next) {
      nextMarker = L.circleMarker([next.coordinates[1], next.coordinates[0]], { radius: 10, color: '#087f72', fillColor: '#34d399', fillOpacity: .8, weight: 3 }).addTo(map);
      const label = document.createElement('span'); label.textContent = `Next: ${next.name}`; nextMarker.bindTooltip(label);
    }
    message(current.finished ? 'End of this mapped service reached. Stop tracking or select your next route.' : 'GPS is live. Your next stop updates as you travel.', 'available', 'GPS live');
    get('live-route-follow').disabled = false;
  }
  start.addEventListener('click', () => {
    if (!route) return;
    if (!window.isSecureContext || !navigator.geolocation) { message('GPS requires HTTPS or localhost and a browser with location support.', 'out', 'GPS unavailable'); return; }
    endTracking(false);
    const token = generation;
    start.disabled = true; stop.disabled = false; follow = true;
    clearGuidance('Finding your location', 'Allow location access when your browser asks.');
    message('Waiting for GPS permission and your first location…', 'in_transit', 'Finding GPS');
    try {
      watch = navigator.geolocation.watchPosition((position) => acceptPosition(position, token), (error) => {
        if (token !== generation) return;
        clearGuidance('GPS unavailable', error.code === 1 ? 'Allow location access in your browser, then start tracking again.' : 'Waiting for a usable location. Check device GPS and signal.');
        message(error.code === 1 ? 'Location permission was denied.' : 'GPS signal is unavailable. Next-stop guidance is paused.', 'out', 'GPS unavailable');
        if (positionMarker) positionMarker.setOpacity(.35);
        if (error.code === 1) endTracking(false);
      }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
      staleTimer = setInterval(() => {
        if (lastGoodTime && Date.now() - lastGoodTime > 30000) {
          progress = null;
          clearGuidance('GPS signal lost', 'Waiting for a fresh, accurate location before showing the next stop.');
          if (positionMarker) positionMarker.setOpacity(.35);
          message('No reliable GPS update for over 30 seconds. Guidance is paused.', 'out', 'GPS stale');
        }
      }, 5000);
    } catch (error) { endTracking(false); clearGuidance('GPS unavailable', 'Check location permissions and try again.'); message('GPS could not start.', 'out', 'GPS unavailable'); }
  });
  stop.addEventListener('click', () => endTracking());
  hint.addEventListener('change', () => {
    progress = null;
    clearGuidance('Checking direction', 'Waiting for the next GPS update to confirm the selected stop.');
    message(watch === null ? 'Direction selected. Start live tracking to continue.' : 'Direction selected. Waiting for a fresh GPS update.', 'in_transit', watch === null ? 'GPS off' : 'Checking direction');
  });
  service.addEventListener('change', showRoute);
  get('live-route-follow').addEventListener('click', () => { follow = true; if (positionMarker) map.setView(positionMarker.getLatLng(), 16); });
  window.addEventListener('pagehide', () => endTracking(false));
  async function initialize() {
    try {
      if (!window.L || !window.LiveNavigation) throw new Error('Map library unavailable');
      const response = await fetch('assets/jeepney-routes.json', { signal: AbortSignal.timeout(12000) });
      if (!response.ok) throw new Error('Route data unavailable');
      const data = await response.json(); routes = data.routes;
      if (!Array.isArray(routes) || !routes.length) throw new Error('No routes');
      const batangasBounds = L.latLngBounds([13.45, 120.45], [14.15, 121.35]);
      map = L.map('live-route-map', {
        maxBounds: batangasBounds,
        maxBoundsViscosity: 1.0,
        minZoom: 11,
      }).setView([13.774, 121.035], 13);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
      network = L.featureGroup().addTo(map);
      map.on('dragstart', () => { follow = false; });
      new ResizeObserver(() => { map.invalidateSize(); if (!positionMarker && network.getLayers().length && map.getSize().x) map.fitBounds(network.getBounds(), { padding: [30, 30] }); }).observe(get('live-route-map'));
      service.replaceChildren(new Option('Choose your service and direction', ''));
      routes.forEach((item) => service.add(new Option(item.name, item.id)));
      service.disabled = false;
    } catch (error) { message('The live map could not load. Check your connection and reload the page.', 'out', 'Map unavailable'); clearGuidance('Map unavailable', 'Reload to try again.'); }
  }
  initialize();
})();
