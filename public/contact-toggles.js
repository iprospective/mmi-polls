// Toggle des moyens de contact directement dans la table Coordonnées.
// Click sur ✓ / — → POST /toggle-contact → réponse JSON {methods: [...]}
// → mise à jour visuelle des 4 boutons de la ligne sans reload de page.

document.addEventListener('DOMContentLoaded', () => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (!csrf) return;

  document.querySelectorAll('button.toggle-cm').forEach((btn) => {
    btn.addEventListener('click', () => toggleCm(btn, csrf));
  });
});

async function toggleCm(btn, csrf) {
  const url    = btn.dataset.toggleUrl;
  const method = btn.dataset.method;
  const pid    = btn.dataset.pid;
  if (!url || !method) return;
  btn.disabled = true;

  try {
    const resp = await fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept':       'application/json',
      },
      body: new URLSearchParams({ _csrf: csrf, method }),
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'unknown');

    // MaJ visuelle de tous les boutons toggle de cette personne
    // (la table Coordonnées en a 4 par ligne).
    const active = new Set(data.methods);
    document.querySelectorAll(`button.toggle-cm[data-pid="${pid}"]`).forEach((b) => {
      const m = b.dataset.method;
      const on = active.has(m);
      b.classList.toggle('is-active', on);
      b.classList.toggle('cm-' + m, on);
      b.textContent = on ? '✓' : '—';
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      const cell = b.closest('td');
      if (cell) {
        cell.classList.toggle('cm-' + m, on);
        cell.dataset.sortValue = on ? '1' : '0';
      }
    });
  } catch (e) {
    console.error('toggle-cm failed:', e);
    btn.classList.add('toggle-err');
    setTimeout(() => btn.classList.remove('toggle-err'), 1200);
  } finally {
    btn.disabled = false;
  }
}
