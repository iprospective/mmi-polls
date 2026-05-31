// Petit tri cliquable pour les tables marquées <table class="sortable">.
// - chaque <th> reçoit le tri au clic (sauf ceux marqués .no-sort)
// - data-sort-type="num" pour les colonnes numériques (sinon auto-détection)
// - data-sort-value sur une cellule force sa valeur de tri
// - on garde le <tfoot> en place (totaux non triés)

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('table.sortable').forEach(initSortable);
});

function initSortable(table) {
  const ths = table.querySelectorAll('thead th');
  ths.forEach((th, idx) => {
    if (th.classList.contains('no-sort')) return;
    th.classList.add('sortable-col');
    th.addEventListener('click', () => sortBy(table, idx, th));
  });
}

function sortBy(table, idx, th) {
  const tbody = table.querySelector('tbody');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  const dir  = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';

  table.querySelectorAll('thead th').forEach((t) => {
    t.removeAttribute('data-sort-dir');
    t.classList.remove('sorted-asc', 'sorted-desc');
  });
  th.dataset.sortDir = dir;
  th.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');

  const type = th.dataset.sortType || 'auto';
  rows.sort((a, b) => {
    const va = cellValue(a.cells[idx], type);
    const vb = cellValue(b.cells[idx], type);
    if (va < vb) return dir === 'asc' ? -1 : 1;
    if (va > vb) return dir === 'asc' ?  1 : -1;
    return 0;
  });
  rows.forEach((r) => tbody.appendChild(r));
}

function cellValue(cell, type) {
  if (!cell) return '';
  const raw = (cell.dataset.sortValue ?? cell.textContent ?? '').trim();
  if (type === 'num' || (type === 'auto' && /^-?[\d.,]+%?$/.test(raw))) {
    return parseFloat(raw.replace(',', '.').replace('%', '')) || 0;
  }
  return raw.toLowerCase();
}
