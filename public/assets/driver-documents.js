(() => {
  'use strict';
  const form = document.getElementById('document-upload-form');
  if (!form) return;
  const input = document.getElementById('document-file');
  const status = document.getElementById('document-file-status');
  const zone = document.getElementById('document-drop-zone');
  const button = form.querySelector('button[type="submit"]');
  function validate() {
    const file = input.files[0];
    let error = '';
    if (file && (!/\.(pdf|jpe?g|png)$/i.test(file.name) || (file.type && !['application/pdf', 'image/jpeg', 'image/png'].includes(file.type)))) error = 'Choose a PDF, JPG or PNG file.';
    else if (file && (!file.size || file.size > 8 * 1024 * 1024)) error = 'Choose a non-empty file up to 8 MB.';
    input.setCustomValidity(error);
    status.textContent = error || (file ? file.name + '  -  ' + (file.size / 1024 / 1024).toFixed(2) + ' MB' : 'No file selected');
    status.classList.toggle('invalid', Boolean(error));
    return !error;
  }
  input.addEventListener('change', validate);
  ['dragenter', 'dragover'].forEach(event => zone.addEventListener(event, e => { e.preventDefault(); zone.classList.add('dragging'); }));
  ['dragleave', 'drop'].forEach(event => zone.addEventListener(event, e => { e.preventDefault(); zone.classList.remove('dragging'); }));
  zone.addEventListener('drop', event => {
    if (event.dataTransfer.files.length !== 1) { status.textContent = 'Choose one document at a time.'; return; }
    input.files = event.dataTransfer.files;
    validate();
  });
  form.addEventListener('submit', event => {
    if (!validate() || !form.reportValidity()) { event.preventDefault(); return; }
    button.disabled = true;
    button.textContent = 'Uploading…';
    form.setAttribute('aria-busy', 'true');
  });
  window.addEventListener('pageshow', () => { button.disabled = false; button.textContent = 'Upload for review ↑'; form.removeAttribute('aria-busy'); });
})();
