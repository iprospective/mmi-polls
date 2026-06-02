// Rendu Leaflet des markers issus du <div id="map-data"> JSON.
// Couleurs par kind : start (vert), end (rouge), participant (bleu),
// self (sky), focus (rose magenta avec icône maman 🤰).
// Trajets (polylines) issus du <div id="trips-data"> JSON quand présent.
// Le trajet du·de la « focus » est toujours visible (override du toggle).
//
// Interactions :
//   - clic sur un pin OU sur une ligne de la table des trajets = highlight
//     ce·tte participant·e (autres pins/lignes/polylines dimés).
//   - clic sur le fond de carte = reset.
//   - <select id="filter-participant"> = masque les autres participant·e·s
//     et leurs trajets (mais garde start/end + focus).
//   - markers à coords identiques : leurs labels sont décalés en rosace
//     pour qu'on puisse tous les lire au lieu de superposer.

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

  // 8 positions radiales autour du pin (horaires 12h, 1:30, 3h, 4:30, …)
  // pour décaler les labels superposés. Format Leaflet : { direction, offset }
  const tooltipPositions = [
    { direction: 'top',    offset: [0,  -26] },
    { direction: 'right',  offset: [14,  -8] },
    { direction: 'bottom', offset: [0,   6]  },
    { direction: 'left',   offset: [-14, -8] },
    { direction: 'top',    offset: [22, -24] },
    { direction: 'top',    offset: [-22,-24] },
    { direction: 'bottom', offset: [22,  4]  },
    { direction: 'bottom', offset: [-22, 4]  },
  ];

  // Détection des doublons de coords (arrondis 5 décimales) pour spread des labels.
  const dupGroups = {};
  markers.forEach((m, i) => {
    if (m.kind !== 'participant' && m.kind !== 'focus' && m.kind !== 'self') return;
    const key = m.lat.toFixed(5) + ',' + m.lng.toFixed(5);
    (dupGroups[key] = dupGroups[key] || []).push(i);
  });
  const groupIndexOf = {};
  Object.values(dupGroups).forEach(idxs => {
    if (idxs.length > 1) idxs.forEach((idx, pos) => { groupIndexOf[idx] = pos; });
  });

  const layerMarkers = [];
  const markersByPid = {};
  markers.forEach((m, i) => {
    const color = ((m.kind === 'participant' || m.kind === 'focus' || m.kind === 'self') && m.color)
      ? m.color : (colors[m.kind] || '#6b7280');
    let icon;
    if (m.kind === 'focus') {
      const inner = focusIconUrl
        ? `<img src="${focusIconUrl}" alt="" class="mm-pin-img">`
        : `<span class="mm-pin-emoji">🤰</span>`;
      icon = L.divIcon({
        className: 'mm-pin mm-pin-focus',
        html: `<div class="mm-pin-body mm-pin-body-focus" style="background:${color}">${inner}</div>`,
        iconSize: [38, 48],
        iconAnchor: [19, 48],
        popupAnchor: [0, -46],
      });
    } else {
      icon = L.divIcon({
        className: 'mm-pin',
        html: `<div class="mm-pin-body" style="background:${color}"><div class="mm-pin-inner"></div></div>`,
        iconSize: [22, 30],
        iconAnchor: [11, 30],
        popupAnchor: [0, -28],
      });
    }
    const marker = L.marker([m.lat, m.lng], { icon, riseOnHover: true }).addTo(map);
    const popupHtml = `<strong>${escapeHtml(m.label)}</strong><br>` +
                      `<small>${escapeHtml(m.desc || '')}</small><br>` +
                      `<small class="muted">${m.lat.toFixed(5)}, ${m.lng.toFixed(5)}</small>`;
    marker.bindPopup(popupHtml);

    // Label permanent : si le pin appartient à un groupe de doublons,
    // on lui donne une position radiale ; sinon, par défaut (top).
    const groupPos = groupIndexOf[i];
    const tooltipOpts = {
      permanent: true,
      className: m.kind === 'focus' ? 'mm-label mm-label-focus' : 'mm-label',
    };
    if (groupPos !== undefined) {
      const pos = tooltipPositions[groupPos % tooltipPositions.length];
      tooltipOpts.direction = pos.direction;
      tooltipOpts.offset = pos.offset;
    } else {
      tooltipOpts.direction = 'top';
      tooltipOpts.offset = m.kind === 'focus' ? [0, -42] : [0, -26];
    }
    marker.bindTooltip(escapeHtml(shortLabel(m.label)), tooltipOpts);

    marker._mmPid = m.pid || 0;
    marker._mmKind = m.kind;
    layerMarkers.push(marker);
    if (m.pid) markersByPid[m.pid] = marker;

    marker.on('click', (ev) => {
      L.DomEvent.stopPropagation(ev);
      highlightPid(marker._mmPid || 0);
    });
  });

  // Trajets : 1 polyline par leg, regroupés par participant (pid) pour
  // filtrage et highlight.
  const tripsEl = document.getElementById('trips-data');
  const tripLayersByPid = {};
  let tripsLayer = null;
  let focusTripLayer = null;
  if (tripsEl) {
    let trips;
    try { trips = JSON.parse(tripsEl.textContent); } catch (e) { trips = []; }
    tripsLayer = L.layerGroup();
    focusTripLayer = L.layerGroup();
    trips.forEach((t) => {
      const target = t.is_focus ? focusTripLayer : tripsLayer;
      const tripGroup = L.layerGroup();
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
        poly._mmPid = t.pid;
        poly._mmBaseOpacity = t.is_focus ? 0.9 : 0.75;
        poly._mmBaseWeight  = t.is_focus ? 5 : 4;
        tripGroup.addLayer(poly);
        poly.on('click', (ev) => {
          L.DomEvent.stopPropagation(ev);
          highlightPid(t.pid);
        });
      });
      tripGroup.addTo(target);
      tripLayersByPid[t.pid] = { group: tripGroup, is_focus: t.is_focus };
    });
    focusTripLayer.addTo(map);
    tripsLayer.addTo(map);

    const toggle = document.getElementById('toggle-routes');
    if (toggle) {
      toggle.addEventListener('change', () => {
        if (toggle.checked) tripsLayer.addTo(map); else map.removeLayer(tripsLayer);
      });
    }
  }

  // Filtre par participant·e (dropdown au-dessus de la carte)
  const filter = document.getElementById('filter-participant');
  if (filter) {
    filter.addEventListener('change', () => {
      const pid = parseInt(filter.value, 10);
      applyFilter(pid);
    });
  }

  function applyFilter(pid) {
    layerMarkers.forEach(mk => {
      if (mk._mmKind === 'start' || mk._mmKind === 'end' || mk._mmKind === 'focus') {
        mk.getElement()?.classList.remove('mm-hidden');
      } else if (mk._mmKind === 'participant' || mk._mmKind === 'self') {
        const hide = pid > 0 && mk._mmPid !== pid;
        const el = mk.getElement();
        if (el) el.classList.toggle('mm-hidden', hide);
      }
    });
    Object.entries(tripLayersByPid).forEach(([p, info]) => {
      const isMatch = pid === 0 || parseInt(p, 10) === pid;
      const shouldShow = info.is_focus || isMatch;
      info.group.eachLayer(l => {
        const el = l.getElement();
        if (el) el.classList.toggle('mm-hidden', !shouldShow);
      });
    });
  }

  // Highlight : met en avant 1 participant·e (dim les autres, agrandit label,
  // bring to front, met une classe sur la ligne du tableau).
  let currentHighlight = 0;
  function highlightPid(pid) {
    if (pid === 0) return clearHighlight();
    if (currentHighlight === pid) return clearHighlight(); // toggle
    currentHighlight = pid;
    layerMarkers.forEach(mk => {
      const el = mk.getElement();
      if (!el) return;
      if (mk._mmKind === 'start' || mk._mmKind === 'end') return;
      const match = mk._mmPid === pid;
      el.classList.toggle('mm-dim', !match);
      el.classList.toggle('mm-active', match);
      if (match) mk.setZIndexOffset(1000); else mk.setZIndexOffset(0);
    });
    Object.entries(tripLayersByPid).forEach(([p, info]) => {
      const match = parseInt(p, 10) === pid;
      info.group.eachLayer(l => {
        l.setStyle({
          opacity: match ? Math.min(1, l._mmBaseOpacity + 0.2) : 0.2,
          weight:  match ? l._mmBaseWeight + 2 : l._mmBaseWeight,
        });
      });
    });
    document.querySelectorAll('.trip-table tbody tr').forEach(tr => {
      tr.classList.toggle('trip-highlight', parseInt(tr.dataset.pid, 10) === pid);
    });
  }
  function clearHighlight() {
    currentHighlight = 0;
    layerMarkers.forEach(mk => {
      const el = mk.getElement();
      if (el) { el.classList.remove('mm-dim'); el.classList.remove('mm-active'); }
      mk.setZIndexOffset(0);
    });
    Object.values(tripLayersByPid).forEach(info => {
      info.group.eachLayer(l => {
        l.setStyle({ opacity: l._mmBaseOpacity, weight: l._mmBaseWeight });
      });
    });
    document.querySelectorAll('.trip-table tbody tr').forEach(tr => tr.classList.remove('trip-highlight'));
  }
  map.on('click', () => clearHighlight());

  // Cliquer une ligne du tableau = même effet que cliquer le pin
  document.querySelectorAll('.trip-table tbody tr').forEach(tr => {
    tr.addEventListener('click', () => {
      const pid = parseInt(tr.dataset.pid, 10);
      if (!pid) return;
      const mk = markersByPid[pid];
      if (mk) map.panTo(mk.getLatLng(), { animate: true });
      highlightPid(pid);
    });
  });

  // Zoom-responsive : ajoute mm-zoom-<bucket> sur le container.
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
    if (tripsLayer)      tripsLayer.eachLayer(g => g.eachLayer(l => bounds.extend(l.getBounds())));
    if (focusTripLayer)  focusTripLayer.eachLayer(g => g.eachLayer(l => bounds.extend(l.getBounds())));
    map.fitBounds(bounds.pad(0.1));
  }
  updateZoomClass();
  map.on('zoomend', updateZoomClass);
});

function shortLabel(s) {
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
