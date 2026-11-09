import { fetchTerminals, mapCoordinates } from './terminals-common.js?v=8';
import { terminalMap } from './terminals-map.js?v=12';

(() => {
  const setText = (id, value) => {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
  };

  const formatCount = value => new Intl.NumberFormat().format(Number(value) || 0);
  // Driver seat reports are stored centrally. Poll the shared endpoint while this
  // page is open so every commuter is shown the same current availability.
  const liveRefreshInterval = 15000;
  let dashboardRequestActive = false;
  let liveRefreshTimer;
  const jeepneyIcon = '<svg class="jeepney-icon" viewBox="0 0 80 42" aria-hidden="true"><path d="M5 28h5l4-16h44l8 8h7v15h-7a8 8 0 0 1-16 0H30a8 8 0 0 1-16 0H5Z" fill="currentColor"/><path d="M18 15h12v10H16Zm16 0h13v10H34Zm17 0h9l5 6v4H51Z" fill="#fff" opacity=".94"/><path d="M18 29h45M33 29V13M49 29V13" stroke="#0e3a69" stroke-width="2"/><circle cx="22" cy="35" r="5" fill="#fff" stroke="#0e3a69" stroke-width="3"/><circle cx="58" cy="35" r="5" fill="#fff" stroke="#0e3a69" stroke-width="3"/></svg>';
  const reportedSeats = driver => {
    const available = Number(driver?.seats?.available);
    const capacity = Number(driver?.seats?.capacity);
    if (!Number.isFinite(available) || !Number.isFinite(capacity) || capacity <= 0) return null;
    const openSeats = Math.max(0, Math.min(capacity, Math.floor(available)));
    return openSeats > 0 ? { available: openSeats, capacity: Math.floor(capacity) } : null;
  };
  const toRadians = degrees => degrees * Math.PI / 180;
  const distanceInMeters = (from, to) => {
    const radius = 6371000;
    const latitude = toRadians(to[0] - from[0]);
    const longitude = toRadians(to[1] - from[1]);
    const value = Math.sin(latitude / 2) ** 2 + Math.cos(toRadians(from[0])) * Math.cos(toRadians(to[0])) * Math.sin(longitude / 2) ** 2;
    return radius * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
  };
  const formatDistance = meters => meters < 1000 ? `${Math.round(meters)} m away` : `${(meters / 1000).toFixed(meters < 10000 ? 1 : 0)} km away`;

  function renderRouteAvailability(terminals) {
    const list = document.getElementById('dashboard-route-availability');
    if (!list) return;
    const terminalsWithJeeps = new Map();
    terminals.forEach(terminal => {
      (terminal.drivers || []).forEach(driver => {
        const seats = reportedSeats(driver);
        if (!seats) return;
        const terminalKey = String(terminal.id);
        const entry = terminalsWithJeeps.get(terminalKey) || { name: terminal.name || 'Terminal', terminalId: terminal.id, terminal, drivers: new Set(), availableSeats: 0, capacity: 0 };
        entry.drivers.add(`${driver.plate_number || ''}|${driver.name || ''}`);
        entry.availableSeats += seats.available;
        entry.capacity += seats.capacity;
        terminalsWithJeeps.set(terminalKey, entry);
      });
    });

    const activeTerminals = [...terminalsWithJeeps.values()].sort((a, b) => b.availableSeats - a.availableSeats || a.name.localeCompare(b.name)).slice(0, 4);
    list.replaceChildren();
    if (!activeTerminals.length) {
      const empty = document.createElement('p');
      empty.textContent = 'No terminals with active jeepneys are currently reported.';
      list.append(empty);
      return;
    }
    activeTerminals.forEach(terminal => {
      const availableSeats = terminal.availableSeats;
      const seatRatio = terminal.capacity ? availableSeats / terminal.capacity : 0;
      const level = seatRatio >= 0.5 ? 'high' : seatRatio >= 0.25 ? 'medium' : 'low';
      const seatLabel = `${availableSeats} ${availableSeats === 1 ? 'seat' : 'seats'} available`;
      const item = document.createElement('a');
      item.className = 'route-availability-item';
      item.href = `user-terminal-details.html?id=${encodeURIComponent(terminal.terminalId)}`;
      item.setAttribute('aria-label', `View details for ${terminal.name}: ${seatLabel}, based on driver seat reports`);
      item.dataset.terminalId = String(terminal.terminalId);
      item.innerHTML = `<strong>${terminal.name.replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[char])}</strong><span class="availability-count availability-${level}" title="Based on current driver-reported open seats">${jeepneyIcon}${seatLabel}</span>`;
      list.append(item);
    });
    labelClosestTerminal(activeTerminals);
  }

  function getCurrentLocation() {
    return new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(
      position => resolve([position.coords.latitude, position.coords.longitude]),
      reject,
      { enableHighAccuracy: true, maximumAge: 30000, timeout: 10000 },
    ));
  }

  async function labelClosestTerminal(activeTerminals) {
    if (!globalThis.isSecureContext || !navigator.geolocation) return;
    const candidates = activeTerminals.map(terminal => ({ terminal, coordinates: mapCoordinates(terminal.terminal) }))
      .filter(candidate => Array.isArray(candidate.coordinates));
    if (!candidates.length) return;
    try {
      const location = await getCurrentLocation();
      const closest = candidates.map(candidate => ({ ...candidate, distance: distanceInMeters(location, candidate.coordinates) }))
        .sort((first, second) => first.distance - second.distance)[0];
    const row = [...document.querySelectorAll('.route-availability-item')]
      .find(item => item.dataset.terminalId === String(closest.terminal.terminalId));
      const title = row?.querySelector('strong');
      if (!title || title.querySelector('.closest-terminal-badge')) return;
      const badge = document.createElement('span');
      badge.className = 'closest-terminal-badge';
      badge.textContent = `Closest to you (${formatDistance(closest.distance)})`;
      badge.title = 'Measured from your current location to the terminal map point.';
      title.append(badge);
    } catch {
      // Do not label a terminal as closest unless the commuter grants location access.
    }
  }

  async function loadDashboardStats() {
    if (dashboardRequestActive) return;
    dashboardRequestActive = true;
    try {
      const response = await fetch(`api/terminals.php?live=${Date.now()}`, { cache: 'no-store' });
      if (!response.ok) throw new Error(`Statistics request failed (${response.status})`);
      const payload = await response.json();
      const terminals = Array.isArray(payload.terminals) ? payload.terminals : [];
      const activeDrivers = new Set();
      terminals.forEach(terminal => {
        (terminal.drivers || []).forEach(driver => {
          if (!reportedSeats(driver)) return;
          activeDrivers.add(`${driver.plate_number || ''}|${driver.name || ''}`);
        });
      });

      setText('dashboard-available-jeeps', formatCount(activeDrivers.size));
      setText('dashboard-active-drivers', formatCount(activeDrivers.size));
      setText('dashboard-terminals', formatCount(terminals.length));
      setText('dashboard-available-jeeps-detail', 'With open seats reported');
      setText('dashboard-active-drivers-detail', 'Registered & verified');
      setText('dashboard-terminals-detail', 'Active boarding points');
      renderRouteAvailability(terminals);
    } catch (error) {
      console.warn('Unable to load commuter dashboard statistics.', error);
      setText('dashboard-available-jeeps-detail', 'Temporarily unavailable');
      setText('dashboard-active-drivers-detail', 'Temporarily unavailable');
      setText('dashboard-terminals-detail', 'Temporarily unavailable');
      const list = document.getElementById('dashboard-route-availability');
      if (list) list.innerHTML = '<p>Live availability is temporarily unavailable.</p>';
    } finally {
      dashboardRequestActive = false;
    }
  }

  loadDashboardStats();
  liveRefreshTimer = window.setInterval(() => {
    if (!document.hidden) loadDashboardStats();
  }, liveRefreshInterval);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadDashboardStats();
  });
  window.addEventListener('pagehide', () => window.clearInterval(liveRefreshTimer), { once: true });

  const availabilityLegend = document.querySelectorAll('.availability-scale small');
  ['50% or more seats open', '25% to 49% seats open', 'Under 25% seats open'].forEach((label, index) => {
    if (availabilityLegend[index]) availabilityLegend[index].textContent = label;
  });

  const mapElement = document.getElementById('dashboard-live-map');
  const mapStatus = document.getElementById('dashboard-map-status');
  if (!mapElement || !mapStatus) return;

  let userLocationMarker;
  let locationWatchId;
  const showCurrentLocation = (map, L) => {
    if (!globalThis.isSecureContext || !navigator.geolocation || locationWatchId !== undefined) return;
    locationWatchId = navigator.geolocation.watchPosition(position => {
      const coordinates = [position.coords.latitude, position.coords.longitude];
      if (userLocationMarker) userLocationMarker.setLatLng(coordinates);
      else {
        userLocationMarker = L.circleMarker(coordinates, {
          radius: 8,
          color: '#ffffff',
          weight: 3,
          fillColor: '#1f78f2',
          fillOpacity: 1,
        }).addTo(map).bindTooltip('Your current location', { direction: 'top', offset: [0, -9] });
      }
      map.panInside(coordinates, { padding: [40, 40], animate: true });
    }, () => {
      // The map remains useful when a commuter declines location access.
    }, {
      enableHighAccuracy: true,
      maximumAge: 30000,
      timeout: 10000,
    });
  };

  const renderSystemMap = terminalMap(mapElement, mapStatus, {
    overviewCenter: [13.757211, 121.058111],
    overviewZoom: 13,
    minZoom: 11,
    startWithOverview: true,
    preserveView: true,
    showPopups: true,
    onSelect: id => { window.location.href = `user-terminal-details.html?id=${encodeURIComponent(id)}`; },
    onReady: ({ map, leaflet }) => showCurrentLocation(map, leaflet),
  });

  fetchTerminals()
    .then(payload => renderSystemMap(Array.isArray(payload.terminals) ? payload.terminals : []))
    .catch(() => { mapStatus.textContent = 'The system map is temporarily unavailable.'; });
})();
