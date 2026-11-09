export function translate(text, values = {}) {
  if (typeof window !== 'undefined' && window.PassengerUI) return window.PassengerUI.t(text, values);
  return text.replace(/\{(\w+)\}/g, (match, name) => values[name] === undefined ? match : String(values[name]));
}

function serviceError(text, status) {
  const error = new Error(translate(text));
  error.originalMessage = text;
  error.status = status;
  return error;
}

export function normalizeSearch(value) {
  return String(value ?? '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

export function filterTerminals(records, query = '', municipality = '') {
  const words = normalizeSearch(query).split(/\s+/).filter(Boolean);
  return records.filter((record) => {
    const searchable = normalizeSearch(`${record.name} ${record.address} ${record.municipality} Batangas`);
    const locationMatch = words.every(word => searchable.includes(word));
    const serviceMatch = publishedTerminalRoutes(record).some(route => {
      const service = normalizeSearch(`${searchable} ${route.name || ''} ${route.destination}`);
      return words.every(word => service.includes(word));
    });
    return (!municipality || record.municipality === municipality) && (locationMatch || serviceMatch);
  });
}

export function publishedTerminalRoutes(record) {
  return (Array.isArray(record.routes) ? record.routes : []).filter(route => route?.verified === true
    && typeof route.destination === 'string' && route.destination.trim());
}

export function matchingTerminalRoutes(record, query = '') {
  const words = normalizeSearch(query).split(/\s+/).filter(Boolean);
  return publishedTerminalRoutes(record).filter(route => {
    const service = normalizeSearch(`${route.name || ''} ${route.destination}`);
    const searchable = normalizeSearch(`${service} ${record.name} ${record.address} ${record.municipality} Batangas`);
    return !words.length || (words.every(word => searchable.includes(word)) && words.some(word => service.includes(word)));
  });
}

export function routeSourceUrl(route) {
  try {
    const url = new URL(route.source_url);
    return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
  } catch { return null; }
}

export function boardingRouteSummary(record, query = '') {
  const matches = matchingTerminalRoutes(record, query);
  const routes = matches.length ? matches : publishedTerminalRoutes(record);
  const summary = element('div', 'boarding-route-summary');
  for (const route of routes) {
    const service = element('div', 'boarding-service');
    service.append(element('strong', '', translate('Jeepney to {destination}', { destination: route.destination })),
      element('p', 'boarding-service-name', route.name));
    const url = routeSourceUrl(route);
    if (url) {
      const link = element('a', 'boarding-source', translate('Route reference'));
      link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
      link.title = route.source_title || translate('Route reference');
      service.append(link);
    }
    summary.append(service);
  }
  summary.hidden = !routes.length;
  const activeCount = Array.isArray(record.drivers) ? record.drivers.length : 0;
  summary.append(element('p', 'active-driver-count', `Active Drivers: ${activeCount}`));
  return summary;
}

export function activeDriversSummary(record) {
  const drivers = Array.isArray(record.drivers) ? record.drivers : [];
  const summary = element('div', 'active-drivers-list');
  if (!drivers.length) {
    summary.append(element('p', 'active-drivers-empty', 'No active drivers are currently assigned to this terminal.'));
    return summary;
  }
  for (const driver of drivers) {
    const item = element('article', 'boarding-driver');
    const details = element('div', 'active-driver-details');
    details.append(
      element('strong', 'active-driver-name', driver.name || 'Driver'),
      element('span', '', `Plate number: ${driver.plate_number || 'Not available'}`),
      element('span', '', driver.seats
        ? `Available seats: ${driver.seats.available} of ${driver.seats.capacity}`
        : 'Available seats: Not available'),
      seatOccupancyIndicator(driver.seats),
      element('small', 'active-driver-updated', driver.seat_updated_at
        ? `Seat count updated: ${driver.seat_updated_at}`
        : 'Seat count has not been reported')
    );
    item.append(details);
    if (Number.isSafeInteger(Number(driver.route_id)) && Number(driver.route_id) > 0) {
      const route = element('a', 'terminal-action active-driver-route', translate('View route'));
      // Route-only mode deliberately does not request a passenger location or
      // calculate passenger-to-terminal directions.
      route.href = `user-map.html?route=${encodeURIComponent(driver.route_id)}&terminal=${encodeURIComponent(record.id)}&location=${encodeURIComponent(driver.location_key || record.slug || '')}&routeOnly=1&from=terminals`;
      route.setAttribute('aria-label', translate('View route for {name}', { name: driver.name || 'driver' }));
      item.append(route);
    }
    summary.append(item);
  }
  return summary;
}

function seatOccupancyIndicator(seats) {
  const available = Number(seats?.available);
  const capacity = Number(seats?.capacity);
  if (!Number.isFinite(available) || !Number.isFinite(capacity) || capacity <= 0) {
    return element('span', 'seat-occupancy seat-occupancy--unknown', 'Seat occupancy unavailable');
  }

  const occupied = Math.max(0, Math.min(capacity, capacity - available));
  const percentage = Math.round((occupied / capacity) * 100);
  const level = percentage < 50 ? 'green'
    : percentage < 70 ? 'yellow'
      : percentage < 90 ? 'orange'
        : 'red';
  return element('span', `seat-occupancy seat-occupancy--${level}`, `${percentage}% occupied`);
}

export function verifiedCoordinates(record) {
  const coordinates = record.coordinates;
  return record.coordinates_verified === true && coordinates
    && typeof coordinates.latitude === 'number' && Number.isFinite(coordinates.latitude)
    && typeof coordinates.longitude === 'number' && Number.isFinite(coordinates.longitude)
    && Math.abs(coordinates.latitude) <= 90 && Math.abs(coordinates.longitude) <= 180
    ? [coordinates.latitude, coordinates.longitude] : null;
}

export function landmarkCoordinates(record) {
  return verifiedCoordinates({ coordinates_verified: record.landmark_coordinates_verified === true, coordinates: record.landmark_coordinates });
}

// Use a checked terminal position or landmark reference without changing boarding verification.
export function mapCoordinates(record) {
  return verifiedCoordinates(record) || landmarkCoordinates(record);
}

export function googleMapsUrl(record) {
  if (typeof record.google_maps_url === 'string') {
    try {
      const url = new URL(record.google_maps_url);
      if (url.protocol === 'https:' && !url.username && !url.password && !url.port && (
        (url.hostname === 'maps.app.goo.gl' && /^\/[A-Za-z0-9]+\/?$/.test(url.pathname))
        || (['www.google.com', 'google.com'].includes(url.hostname) && url.pathname.startsWith('/maps/'))
      )) return url.href;
    } catch { /* Invalid references must not become clickable URLs. */ }
  }
  const coordinates = mapCoordinates(record);
  if (!coordinates) return null;
  const url = new URL('https://www.google.com/maps/search/');
  url.searchParams.set('api', '1');
  url.searchParams.set('query', coordinates.join(','));
  return url.href;
}

export function directionsUrl(record) {
  const coordinates = mapCoordinates(record);
  if (!coordinates) return null;
  const url = new URL('https://www.google.com/maps/dir/');
  url.searchParams.set('api', '1');
  url.searchParams.set('destination', coordinates.join(','));
  return url.href;
}

export function detailsUrl(id) {
  return `user-terminal-details.html?id=${encodeURIComponent(id)}`;
}

export async function fetchTerminals(id = null) {
  const url = new URL('../api/terminals.php', import.meta.url);
  if (id !== null) url.searchParams.set('id', id);
  url.searchParams.set('_', String(Date.now()));
  let response;
  try {
    response = await fetch(url, { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' }, signal: AbortSignal.timeout(12000) });
  } catch {
    throw serviceError('Locations could not be loaded. Check your connection and try again.');
  }
  let result;
  try { result = await response.json(); } catch {
    throw serviceError('Locations could not be loaded. This page needs the project PHP server.');
  }
  if (!response.ok) {
    throw serviceError(result.error || 'Terminal information is temporarily unavailable. Please try again.', response.status);
  }
  return result;
}

export function element(tag, className = '', text = '') {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

export function locationIcon() {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.8');
  svg.setAttribute('class', 'dashboard-icon');
  svg.setAttribute('aria-hidden', 'true');
  for (const [tag, attributes] of [
    ['path', { d: 'M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z' }],
    ['circle', { cx: '12', cy: '10', r: '2.5' }],
  ]) {
    const child = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([key, value]) => child.setAttribute(key, value));
    svg.append(child);
  }
  return svg;
}
