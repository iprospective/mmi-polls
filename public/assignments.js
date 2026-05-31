// Astreintes — gestion live des compteurs (P/S) et de la règle
// "une personne ne peut pas être à la fois principale et suppléante
// sur le même créneau".
//
// Comportement :
// - Initialise les compteurs à partir des sélections en base.
// - À chaque changement : met à jour les compteurs, désactive
//   l'option correspondante dans l'autre <select> de la ligne, et,
//   si l'autre rôle était sur la même personne, le libère.
// - Le libellé des options est régénéré pour refléter les compteurs
//   et un suffixe explicite quand l'option est bloquée par l'autre rôle.

document.addEventListener('DOMContentLoaded', () => {
  const selects = document.querySelectorAll('select.assign-select');
  if (!selects.length) return;

  // Map pid -> { primary, backup }
  const counts = new Map();
  const getC = (pid) => {
    if (!counts.has(pid)) counts.set(pid, { primary: 0, backup: 0 });
    return counts.get(pid);
  };

  // Init compteurs depuis l'état serveur
  selects.forEach((sel) => {
    sel.dataset.prev = sel.value;
    if (sel.value && sel.value !== '0') {
      getC(sel.value)[sel.dataset.role]++;
    }
  });

  function refreshLabels() {
    document.querySelectorAll('select.assign-select option[data-pid]').forEach((opt) => {
      const pid = opt.dataset.pid;
      if (pid === '0' || !opt.dataset.name) return;
      const c = counts.get(pid) || { primary: 0, backup: 0 };
      const base = `${opt.dataset.name} (${c.primary}P / ${c.backup}S)`;
      opt.textContent = opt.dataset.blocked === '1'
        ? `${base} — déjà sur l'autre rôle`
        : base;
    });
  }

  function syncBlockedStates() {
    document.querySelectorAll('tr').forEach((tr) => {
      const pair = tr.querySelectorAll('select.assign-select');
      if (pair.length !== 2) return;
      pair.forEach((sel) => {
        const other = pair[0] === sel ? pair[1] : pair[0];
        const blockedPid = other.value;
        sel.querySelectorAll('option[data-pid]').forEach((opt) => {
          if (opt.dataset.pid === '0') return;
          const blocked = blockedPid !== '0' && opt.value === blockedPid && opt.value !== sel.value;
          opt.disabled = blocked;
          opt.dataset.blocked = blocked ? '1' : '0';
        });
      });
    });
  }

  function refreshTotals() {
    const el = document.getElementById('assignments-totals');
    if (!el) return;
    let nP = 0, nS = 0;
    selects.forEach((sel) => {
      if (sel.value !== '0') {
        if (sel.dataset.role === 'primary') nP++; else nS++;
      }
    });
    el.textContent = `Sélections en cours : ${nP} principal·e·s / ${nS} suppléant·e·s`;
  }

  function applyChange(sel) {
    const tr = sel.closest('tr');
    const pair = tr.querySelectorAll('select.assign-select');
    const other = pair[0] === sel ? pair[1] : pair[0];

    // 1. Anti-conflit : si la nouvelle valeur correspond à la valeur
    //    courante de l'autre rôle, on libère l'autre.
    if (sel.value !== '0' && other.value === sel.value) {
      const oldOther = other.dataset.prev;
      if (oldOther && oldOther !== '0' && counts.has(oldOther)) {
        const c = counts.get(oldOther);
        c[other.dataset.role] = Math.max(0, c[other.dataset.role] - 1);
      }
      other.value = '0';
      other.dataset.prev = '0';
    }

    // 2. MaJ des compteurs pour le select qui vient de changer.
    const prev = sel.dataset.prev;
    const next = sel.value;
    const role = sel.dataset.role;
    if (prev && prev !== '0' && counts.has(prev)) {
      const c = counts.get(prev);
      c[role] = Math.max(0, c[role] - 1);
    }
    if (next && next !== '0') {
      getC(next)[role]++;
    }
    sel.dataset.prev = next;

    // 3. Rafraîchit l'UI.
    syncBlockedStates();
    refreshLabels();
    refreshTotals();
  }

  selects.forEach((sel) => sel.addEventListener('change', () => applyChange(sel)));

  syncBlockedStates();
  refreshLabels();
  refreshTotals();
});
