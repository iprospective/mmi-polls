// Active Quill sur chaque .wysiwyg trouvé dans la page.
// Le markup attendu :
//   <textarea name="description" data-wysiwyg style="display:none">…HTML initial…</textarea>
//   <div class="wysiwyg-editor"></div>
// Le textarea reçoit le HTML du Quill au submit du formulaire parent.

document.addEventListener('DOMContentLoaded', () => {
  if (typeof Quill === 'undefined') return;

  document.querySelectorAll('textarea[data-wysiwyg]').forEach((textarea) => {
    const editor = textarea.nextElementSibling;
    if (!editor || !editor.classList.contains('wysiwyg-editor')) return;

    const quill = new Quill(editor, {
      theme: 'snow',
      placeholder: textarea.getAttribute('data-placeholder') || '',
      modules: {
        toolbar: [
          [{ header: [2, 3, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['blockquote', 'link'],
          ['clean'],
        ],
      },
    });

    const initial = textarea.value;
    if (initial.trim() !== '') {
      // Si le contenu existant est du HTML (Quill ou autre) on l'injecte tel quel ;
      // sinon on traite comme du texte brut (préserve les retours à la ligne).
      if (/<(p|br|h[1-6]|strong|em|ul|ol|li|a|blockquote|pre|code|span|hr)\b/i.test(initial)) {
        quill.clipboard.dangerouslyPasteHTML(initial);
      } else {
        quill.setText(initial);
      }
    }

    const form = textarea.closest('form');
    if (form) {
      form.addEventListener('submit', () => {
        const text = quill.getText().trim();
        textarea.value = text === '' ? '' : quill.root.innerHTML;
      });
    }
  });
});
