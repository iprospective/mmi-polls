// Met à jour en direct le compteur (Pₓ / Sᵧ) affiché dans chaque <option>
// des <select> d'astreintes, en fonction des sélections courantes.

document.addEventListener('DOMContentLoaded', () => {
  const selects = document.querySelectorAll('select.assign-select');
  if (!selects.length) return;

  // Initialise les compteurs à partir des valeurs sélectionnées.
  const counts = new Map(); // pid (string) -> { primary, backup }
  const getC = (pid) => {
    if (!counts.has(pid)) counts.set(pid, { primary: 0, backup: 0 });
    return counts.get(pid);
  };

  selects.forEach((sel) => {
    const pid = sel.value;
    const role = sel.dataset.role;
    if (pid && pid !== '0') {
      getC(pid)[role]++;
    }
    sel.dataset.prev = pid;
  });

  function refreshLabels() {
    document.querySelectorAll('select.assign-select option[data-pid]').forEach((opt) => {
      const pid = opt.dataset.pid;
      if (pid === '0' || !opt.dataset.name) return;
      const c = counts.get(pid) || { primary: 0, backup: 0 };
      opt.textContent = `${opt.dataset.name} (${c.primary}P / ${c.backup}S)`;
    });
  }

  function refreshTotals() {
    const totalsEl = document.getElementById('assignments-totals');
    if (!totalsEl) return;
    let nP = 0, nS = 0;
    selects.forEach((sel) => {
      if (sel.value !== '0') {
        if (sel.dataset.role === 'primary') nP++; else nS++;
      }
    });
    totalsEl.textContent = `Sélections en cours : ${nP} principal·e·s / ${nS} suppléant·e·s`;
  }

  selects.forEach((sel) => {
    sel.addEventListener('change', () => {
      const role = sel.dataset.role;
      const prev = sel.dataset.prev;
      const next = sel.value;
      if (prev && prev !== '0') {
        const c = getC(prev);
        c[role] = Math.max(0, c[role] - 1);
      }
      if (next && next !== '0') {
        getC(next)[role]++;
      }
      sel.dataset.prev = next;
      refreshLabels();
      refreshTotals();
    });
  });

  refreshLabels();
  refreshTotals();
});
