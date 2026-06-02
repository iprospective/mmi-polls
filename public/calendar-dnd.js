// Drag'n'drop des astreintes sur le calendrier admin.
// Source : <span class="cal-pair dnd-draggable" draggable="true" data-cid data-role data-pid data-slot-label data-day>
// Cible  : tout <span class="cal-pair dnd-cell">
// Sur drop valide → ouvre #move-modal avec récap, soumission AJAX vers
// /admin/.../calendar/move. Pas de drop sur soi-même.

document.addEventListener('DOMContentLoaded', () => {
  const cells = document.querySelectorAll('.dnd-cell');
  if (!cells.length) return;
  const modal = document.getElementById('move-modal');
  const form  = document.getElementById('move-form');
  const summary = document.getElementById('move-summary');
  const fSrcCid = document.getElementById('move-src-cid');
  const fSrcRole = document.getElementById('move-src-role');
  const fDstCid = document.getElementById('move-dst-cid');
  const fDstRole = document.getElementById('move-dst-role');
  const fMessage = document.getElementById('move-message');
  const btnCancel = document.getElementById('move-cancel');
  const btnSubmit = document.getElementById('move-submit');
  if (!modal || !form) return;

  let dragSource = null;

  cells.forEach((el) => {
    if (el.classList.contains('dnd-draggable')) {
      el.addEventListener('dragstart', (ev) => {
        dragSource = el;
        el.classList.add('dnd-dragging');
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('text/plain', el.dataset.pid + '@' + el.dataset.cid + ':' + el.dataset.role);
        }
      });
      el.addEventListener('dragend', () => {
        el.classList.remove('dnd-dragging');
        document.querySelectorAll('.dnd-over').forEach(n => n.classList.remove('dnd-over'));
      });
    }
    el.addEventListener('dragover', (ev) => {
      if (!dragSource || dragSource === el) return;
      // Pas de drop sur soi-même (même slot + même rôle).
      if (sameCell(dragSource, el)) return;
      ev.preventDefault();
      if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
      el.classList.add('dnd-over');
    });
    el.addEventListener('dragleave', () => {
      el.classList.remove('dnd-over');
    });
    el.addEventListener('drop', (ev) => {
      ev.preventDefault();
      el.classList.remove('dnd-over');
      if (!dragSource || dragSource === el || sameCell(dragSource, el)) return;
      // Anti-doublon : si la personne est déjà sur le slot cible (autre rôle),
      // on ne propose pas — ça créerait un conflit (interdit par l'algo).
      if (alreadyOnTargetSlot(dragSource, el)) {
        alert('Cette personne est déjà assignée sur ce créneau (autre rôle). Impossible de la mettre sur les deux.');
        return;
      }
      openModal(dragSource, el);
    });
  });

  function sameCell(a, b) {
    return a.dataset.cid === b.dataset.cid && a.dataset.role === b.dataset.role;
  }

  // Cherche dans le DOM si la personne (pid) est déjà sur le slot cible
  // (même choice_id) dans l'autre rôle.
  function alreadyOnTargetSlot(src, dst) {
    const targetCid = dst.dataset.cid;
    const otherRole = dst.dataset.role === 'primary' ? 'backup' : 'primary';
    const other = document.querySelector(`.dnd-cell[data-cid="${targetCid}"][data-role="${otherRole}"]`);
    if (!other) return false;
    return other.dataset.pid === src.dataset.pid && src.dataset.pid !== '0';
  }

  function openModal(src, dst) {
    const srcName = src.querySelector('.cal-name')?.textContent.trim() || '';
    const dstName = dst.querySelector('.cal-name')?.textContent.trim() || '';
    const srcSlot = `${src.dataset.day} ${src.dataset.slotLabel} (${src.dataset.role === 'primary' ? 'P' : 'S'})`;
    const dstSlot = `${dst.dataset.day} ${dst.dataset.slotLabel} (${dst.dataset.role === 'primary' ? 'P' : 'S'})`;
    const isSwap = dst.classList.contains('dnd-draggable');
    if (isSwap) {
      summary.innerHTML = `<strong>Échange</strong><br>` +
        `${escapeHtml(srcName)} (${escapeHtml(srcSlot)}) ↔ ${escapeHtml(dstName)} (${escapeHtml(dstSlot)})<br>` +
        `Les deux personnes devront accepter par email.`;
    } else {
      summary.innerHTML = `<strong>Déplacement</strong><br>` +
        `${escapeHtml(srcName)} : ${escapeHtml(srcSlot)} → ${escapeHtml(dstSlot)}<br>` +
        `${escapeHtml(srcName)} devra accepter par email.`;
    }
    fSrcCid.value = src.dataset.cid;
    fSrcRole.value = src.dataset.role;
    fDstCid.value = dst.dataset.cid;
    fDstRole.value = dst.dataset.role;
    fMessage.value = '';
    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', '');
    fMessage.focus();
  }

  btnCancel.addEventListener('click', () => {
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Envoi…';
    try {
      const fd = new FormData(form);
      const r = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();
      if (!data.ok) {
        alert('Erreur : ' + (data.error || 'inconnue'));
        btnSubmit.disabled = false;
        btnSubmit.textContent = '📨 Envoyer la demande';
        return;
      }
      // Succès : recharge la page pour voir la demande dans le récap.
      window.location.reload();
    } catch (e) {
      alert('Erreur réseau : ' + e.message);
      btnSubmit.disabled = false;
      btnSubmit.textContent = '📨 Envoyer la demande';
    }
  });

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
});
