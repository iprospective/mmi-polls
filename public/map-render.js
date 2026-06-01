// Rendu Leaflet des markers issus du <div id="map-data"> JSON.
// Couleurs par kind : start (vert), end (rouge), participant/self (bleu).

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
    const color = colors[m.kind] || '#6b7280';
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

  const group = L.featureGroup(layerMarkers);
  if (layerMarkers.length === 1) {
    map.setView(layerMarkers[0].getLatLng(), 12);
  } else {
    map.fitBounds(group.getBounds().pad(0.15));
  }
});

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
