// Rendu Leaflet des markers issus du <div id="map-data"> JSON.
// Couleurs par kind : start (vert), end (rouge), participant (bleu),
// self (sky), focus (rose magenta avec icône maman 🤰).
// Trajets (polylines) issus du <div id="trips-data"> JSON quand présent.
// Le trajet du·de la « focus » est toujours visible (override du toggle).

document.addEventListener('DOMContentLoaded', () => {
  if (typeof L === 'undefined') return;
  const dataEl = document.getElementById('map-data');
  const mapEl  = document.getElementById('map');
  if (!dataEl || !mapEl) return;

  let markers;
  try { markers = JSON.parse(dataEl.textContent); } catch (e) { return; }
  if (!Array.isArray(markers) || markers.length === 0) return;

  const focusIconEl = document.getElementById('focus-icon-url');
  const focusIconUrl = focusIconEl ? focusIconEl.textContent.trim() : '';

  const map = L.map(mapEl, { scrollWheelZoom: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
  }).addTo(map);

  const colors = {
    start:       '#16a34a',
    end:         '#dc2626',
    self:        '#0284c7',
    participant: '#2563eb',
    focus:       '#db2777',
  };

  const layerMarkers = [];
  markers.forEach((m) => {
    const color = ((m.kind === 'participant' || m.kind === 'focus') && m.color)
      ? m.color : (colors[m.kind] || '#6b7280');
    let icon;
    if (m.kind === 'focus') {
      // Focus : pin plus large, image custom si dispo, sinon emoji 🤰.
      const inner = focusIconUrl
        ? `<img src="${focusIconUrl}" alt="" class="mm-pin-img">`
        : `<span class="mm-pin-emoji">🤰</span>`;
      icon = L.divIcon({
        className: 'mm-pin mm-pin-focus',
        html: `<div class="mm-pin-body mm-pin-body-focus" style="background:${color}">${inner}</div>`,
        iconSize: [38, 48],
        iconAnchor: [19, 48],
        popupAnchor: [0, -46],
        tooltipAnchor: [0, -42],
      });
    } else {
      icon = L.divIcon({
        className: 'mm-pin',
        html: `<div class="mm-pin-body" style="background:${color}"><div class="mm-pin-inner"></div></div>`,
        iconSize: [22, 30],
        iconAnchor: [11, 30],
        popupAnchor: [0, -28],
        tooltipAnchor: [0, -26],
      });
    }
    const marker = L.marker([m.lat, m.lng], { icon, riseOnHover: true }).addTo(map);
    const popupHtml = `<strong>${escapeHtml(m.label)}</strong><br>` +
                      `<small>${escapeHtml(m.desc || '')}</small><br>` +
                      `<small class="muted">${m.lat.toFixed(5)}, ${m.lng.toFixed(5)}</small>`;
    marker.bindPopup(popupHtml);

    // Label permanent (prénom seul, court, sans la longue description).
    // direction:'top' pose le tooltip au-dessus du pin pour éviter le
    // chevauchement avec d'autres marqueurs proches.
    const labelClass = m.kind === 'focus' ? 'mm-label mm-label-focus' : 'mm-label';
    marker.bindTooltip(escapeHtml(shortLabel(m.label)), {
      permanent: true,
      direction: 'top',
      offset: m.kind === 'focus' ? [0, -42] : [0, -26],
      className: labelClass,
    });
    layerMarkers.push(marker);
  });

  // Trajets : 1 polyline par leg. Le trajet du focus est sur sa propre
  // layer ALWAYS-on. Les autres sur tripsLayer toggable.
  const tripsEl = document.getElementById('trips-data');
  let tripsLayer = null;
  let focusTripLayer = null;
  if (tripsEl) {
    let trips;
    try { trips = JSON.parse(tripsEl.textContent); } catch (e) { trips = []; }
    tripsLayer = L.layerGroup();
    focusTripLayer = L.layerGroup();
    trips.forEach((t) => {
      const target = t.is_focus ? focusTripLayer : tripsLayer;
      t.legs.forEach((leg) => {
        const latlngs = leg.geometry.map(([lng, lat]) => [lat, lng]);
        if (latlngs.length < 2) return;
        const poly = L.polyline(latlngs, {
          color: t.color,
          weight: t.is_focus ? 5 : 4,
          opacity: t.is_focus ? 0.9 : 0.75,
          dashArray: leg.name === 'start_to_end' ? '6, 6' : null,
        });
        poly.bindTooltip(`<strong>${escapeHtml(t.name)}</strong> · ${legLabel(leg.name)} · ${fmtKm(leg.distance_m)}`,
          { sticky: true, direction: 'top' });
        target.addLayer(poly);
      });
    });
    focusTripLayer.addTo(map);
    tripsLayer.addTo(map);

    // Toggle uniquement les trajets non-focus (la maman reste toujours visible).
    const toggle = document.getElementById('toggle-routes');
    if (toggle) {
      toggle.addEventListener('change', () => {
        if (toggle.checked) tripsLayer.addTo(map); else map.removeLayer(tripsLayer);
      });
    }
  }

  // Zoom-responsive : ajoute mm-zoom-<bucket> sur le container pour
  // que les labels et pins grossissent au zoom (réglé en CSS).
  const updateZoomClass = () => {
    const z = map.getZoom();
    const bucket = z < 9 ? 'far' : (z < 12 ? 'mid' : (z < 15 ? 'near' : 'close'));
    mapEl.classList.remove('mm-zoom-far', 'mm-zoom-mid', 'mm-zoom-near', 'mm-zoom-close');
    mapEl.classList.add('mm-zoom-' + bucket);
  };

  const group = L.featureGroup(layerMarkers);
  if (layerMarkers.length === 1) {
    map.setView(layerMarkers[0].getLatLng(), 12);
  } else {
    const bounds = group.getBounds();
    if (tripsLayer)      tripsLayer.eachLayer(l => bounds.extend(l.getBounds()));
    if (focusTripLayer)  focusTripLayer.eachLayer(l => bounds.extend(l.getBounds()));
    map.fitBounds(bounds.pad(0.1));
  }
  updateZoomClass();
  map.on('zoomend', updateZoomClass);
});

function shortLabel(s) {
  // Limite à ~14 caractères + ellipsis pour ne pas surcharger la carte.
  s = String(s);
  return s.length > 14 ? s.slice(0, 13) + '…' : s;
}

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
