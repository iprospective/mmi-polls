// Rendu Leaflet des markers issus du <div id="map-data"> JSON.
// Couleurs par kind : start (vert), end (rouge), participant/self (bleu).
// Trajets (polylines) issus du <div id="trips-data"> JSON quand présent.

document.addEventListener('DOMContentLoaded', () => {
  if (typeof L === 'undefined') return;
  const dataEl = document.getElementById('map-data');
  const mapEl  = document.getElementById('map');
  if (!dataEl || !mapEl) return;

  let markers;
  try { markers = JSON.parse(dataEl.textContent); } catch (e) { return; }
  if (!Array.isArray(markers) || markers.length === 0) return;

  // Init map sans setView : on ajuste avec fitBounds plus bas
  const map = L.map(mapEl, { scrollWheelZoom: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
  }).addTo(map);

  const colors = {
    start:       '#16a34a',  // vert
    end:         '#dc2626',  // rouge
    self:        '#0284c7',  // bleu sky pour soi-même
    participant: '#2563eb',  // bleu standard pour les autres
  };

  const layerMarkers = [];
  markers.forEach((m) => {
    // Marker participant : couleur per-participant si fournie (carte admin
    // avec trajets), sinon fallback sur la palette par kind.
    const color = (m.kind === 'participant' && m.color) ? m.color : (colors[m.kind] || '#6b7280');
    const icon = L.divIcon({
      className: 'mm-pin',
      html: `<div class="mm-pin-body" style="background:${color}"><div class="mm-pin-inner"></div></div>`,
      iconSize: [22, 30],
      iconAnchor: [11, 30],
      popupAnchor: [0, -28],
    });
    const marker = L.marker([m.lat, m.lng], { icon }).addTo(map);
    const html = `<strong>${escapeHtml(m.label)}</strong><br>` +
                 `<small>${escapeHtml(m.desc || '')}</small><br>` +
                 `<small class="muted">${m.lat.toFixed(5)}, ${m.lng.toFixed(5)}</small>`;
    marker.bindPopup(html);
    layerMarkers.push(marker);
  });

  // Trajets : 1 polyline par leg, regroupées en LayerGroup pour pouvoir
  // toggle on/off. Geometry = [[lng,lat], …] (format GeoJSON / OSRM),
  // Leaflet attend [lat, lng] donc on swap.
  const tripsEl = document.getElementById('trips-data');
  let tripsLayer = null;
  if (tripsEl) {
    let trips;
    try { trips = JSON.parse(tripsEl.textContent); } catch (e) { trips = []; }
    tripsLayer = L.layerGroup();
    trips.forEach((t) => {
      t.legs.forEach((leg, i) => {
        const latlngs = leg.geometry.map(([lng, lat]) => [lat, lng]);
        if (latlngs.length < 2) return;
        const poly = L.polyline(latlngs, {
          color: t.color,
          weight: 4,
          opacity: 0.75,
          // Pointillés pour leg start→end (partagé par tou·te·s), plein
          // pour les legs propres au participant·e.
          dashArray: leg.name === 'start_to_end' ? '6, 6' : null,
        });
        const label = legLabel(leg.name);
        const km = fmtKm(leg.distance_m);
        poly.bindTooltip(`<strong>${escapeHtml(t.name)}</strong> · ${label} · ${km}`,
          { sticky: true, direction: 'top' });
        tripsLayer.addLayer(poly);
      });
    });
    tripsLayer.addTo(map);

    const toggle = document.getElementById('toggle-routes');
    if (toggle) {
      toggle.addEventListener('change', () => {
        if (toggle.checked) tripsLayer.addTo(map); else map.removeLayer(tripsLayer);
      });
    }
  }

  const group = L.featureGroup(layerMarkers);
  if (layerMarkers.length === 1) {
    map.setView(layerMarkers[0].getLatLng(), 12);
  } else {
    // Inclure les trajets dans le fitBounds pour ne pas couper les routes
    const bounds = group.getBounds();
    if (tripsLayer) {
      tripsLayer.eachLayer(l => bounds.extend(l.getBounds()));
    }
    map.fitBounds(bounds.pad(0.1));
  }
});

function legLabel(name) {
  return {
    home_to_start: 'chez → départ',
    start_to_end:  'départ → arrivée',
    end_to_home:   'arrivée → chez',
  }[name] || name;
}

function fmtKm(m) {
  if (m >= 1000) return (m / 1000).toFixed(1).replace('.', ',') + ' km';
  return m + ' m';
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
