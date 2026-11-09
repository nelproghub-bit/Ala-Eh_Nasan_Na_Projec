/* Browser-local passenger preferences. This does not create or authenticate an account. */
(() => {
  'use strict';
  const key = 'alaEhPassengerPreferences';
  const accountEndpoint = 'api/auth.php';
  const defaults = { name: '', theme: 'light', language: 'en' };
  const cleanName = value => typeof value === 'string' ? Array.from(value.replace(/[\u0000-\u001f\u007f]/g, '').trim().replace(/\s+/g, ' ')).slice(0, 60).join('') : '';
  const validPhoto = value => typeof value === 'string' && value.length <= 200000 && /^data:image\/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$/.test(value);
  const initialsFor = value => { const cleaned = cleanName(value); return cleaned ? cleaned.split(' ').slice(0, 2).map(word => Array.from(word)[0]).join('').toLocaleUpperCase() : 'P'; };
  function normalize(value) {
    return { name: cleanName(value?.name) || defaults.name,
      theme: ['light', 'dark', 'system'].includes(value?.theme) ? value.theme : defaults.theme,
      language: ['en', 'fil'].includes(value?.language) ? value.language : defaults.language,
      ...(validPhoto(value?.photo) ? { photo: value.photo } : {}) };
  }
  function read() {
    try { return normalize(JSON.parse(localStorage.getItem(key))); } catch { return { ...defaults }; }
  }
  let settings = read();
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  function t(text, values = {}) {
    text = window.PassengerLanguage?.translate(text, settings.language) ?? text;
    return text.replace(/\{(\w+)\}/g, (match, name) => values[name] === undefined ? match : String(values[name]));
  }
  function applyTheme() {
    document.documentElement.lang = settings.language;
    document.documentElement.dataset.theme = settings.theme === 'system' ? (media.matches ? 'dark' : 'light') : settings.theme;
  }
  applyTheme();
  media.addEventListener('change', () => { if (settings.theme === 'system') applyTheme(); });
  let applyPreferences = applyTheme;
  function savePreferences(value) {
    const next = normalize({ ...settings, ...value });
    localStorage.setItem(key, JSON.stringify(next));
    settings = next;
    applyPreferences();
    return { ...settings };
  }
  async function updateAccountName(name) {
    const response = await fetch(`${accountEndpoint}?action=update-name`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ full_name: name }) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Could not update your account name.');
    return result.account;
  }
  async function syncAccountName() {
    try {
      const response = await fetch(`${accountEndpoint}?action=session`, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) return;
      const result = await response.json();
      const accountName = cleanName(result.account?.full_name);
      // The account name is the source of truth: it is the name supplied at
      // registration (or deliberately updated later in Profile settings).
      if (accountName && accountName !== settings.name) savePreferences({ name: accountName });
    } catch { /* user.js handles access/session errors */ }
  }
  window.PassengerUI = { t, getPreferences: () => ({ ...settings }), savePreferences, updateAccountName, syncAccountName };

  // Store a small, decoded raster image rather than the original camera file.
  async function preparePhoto(file) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) throw new Error('Choose a JPG, PNG or WebP image.');
    if (file.size > 10 * 1024 * 1024) throw new Error('Choose a photo smaller than 10 MB.');
    const url = URL.createObjectURL(file);
    try {
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error('This image could not be opened. Try another photo.'));
        image.src = url;
      });
      const side = Math.min(image.naturalWidth, image.naturalHeight);
      if (!side) throw new Error('This image could not be opened. Try another photo.');
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 256;
      const context = canvas.getContext('2d');
      if (!context) throw new Error('This image could not be processed. Try another photo.');
      context.fillStyle = '#fff';
      context.fillRect(0, 0, 256, 256);
      context.drawImage(image, (image.naturalWidth - side) / 2, (image.naturalHeight - side) / 2, side, side, 0, 0, 256, 256);
      const photo = canvas.toDataURL('image/jpeg', .85);
      if (!validPhoto(photo)) throw new Error('This photo could not be saved. Try another photo.');
      return photo;
    } finally { URL.revokeObjectURL(url); }
  }

  function renderAvatar(node, photo, name) {
    node.textContent = initialsFor(name);
    if (!validPhoto(photo)) return;
    const image = document.createElement('img');
    image.alt = '';
    image.onerror = () => { node.textContent = initialsFor(name); };
    image.src = photo;
    node.textContent = '';
    node.append(image);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const profile = document.querySelector('.passenger-profile');
    if (!profile) return;
    const edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'profile-edit';
    edit.setAttribute('aria-label', 'Edit profile');
    edit.setAttribute('aria-haspopup', 'dialog');
    edit.setAttribute('aria-controls', 'profile-settings');
    edit.innerHTML = '<svg width="18" height="22" viewBox="17 9 24 30" fill="currentColor" aria-hidden="true"><g class="profile-pencil" stroke-linejoin="round"><path d="m31 14 6 4-11 16-7 3 1-8 11-15Z"/><path d="m32 12 1-1c1-1 2-1 3 0l2 1c1 1 2 2 1 3l-1 2-6-5Z"/></g></svg>';
    profile.append(edit);
    const dialog = document.createElement('dialog');
    dialog.id = 'profile-settings';
    dialog.className = 'profile-dialog';
    dialog.setAttribute('aria-labelledby', 'profile-settings-title');
    // This is fixed application markup. User names are only assigned via value/textContent.
    dialog.innerHTML = `<form id="profile-settings-form" class="profile-settings-form">
      <h2 id="profile-settings-title">Profile settings</h2><p class="profile-intro">Make your commute feel more like you.</p>
      <span class="profile-photo-label">Profile picture</span>
      <div class="profile-photo-picker">
        <div id="profile-photo-preview" class="profile-photo-preview" aria-hidden="true"></div>
        <div class="profile-photo-controls"><div class="profile-photo-actions"><button type="button" id="profile-photo-choose">Choose photo</button><button type="button" id="profile-photo-remove" hidden>Remove</button></div>
          <input type="file" id="profile-photo" accept="image/jpeg,image/png,image/webp" aria-label="Choose profile picture" hidden>
          <p id="profile-photo-help">JPG, PNG or WebP, up to 10 MB. Cropped to fit the circle.</p>
          <p id="profile-photo-status" role="status"></p>
        </div>
      </div>
      <label for="profile-name">Display name<input id="profile-name" name="name" autocomplete="nickname" required maxlength="60"></label>
      <label for="profile-theme">Theme<select id="profile-theme" name="theme"><option value="light">Light</option><option value="dark">Dark</option><option value="system">Match device</option></select></label>
      <p class="profile-storage-note">Saved on this browser.</p><p id="profile-error" class="profile-error" role="alert" hidden></p>
      <div class="profile-dialog-actions"><button type="button" id="profile-cancel">Cancel</button><button type="submit" id="profile-save" class="profile-save">Save changes</button></div>
    </form>`;
    document.body.append(dialog);
    const form = document.getElementById('profile-settings-form');
    const name = document.getElementById('profile-name');
    const theme = document.getElementById('profile-theme');
    const error = document.getElementById('profile-error');
    const photoInput = document.getElementById('profile-photo');
    const photoPreview = document.getElementById('profile-photo-preview');
    const photoRemove = document.getElementById('profile-photo-remove');
    const photoStatus = document.getElementById('profile-photo-status');
    const save = document.getElementById('profile-save');
    let draftPhoto = '', photoRequest = 0, photoPending = false;
    function updatePhotoPreview() {
      renderAvatar(photoPreview, draftPhoto, name.value);
      photoRemove.hidden = !draftPhoto;
    }
    function finishPhotoRequest() {
      photoPending = false;
      save.disabled = false;
      photoStatus.textContent = '';
    }
    document.getElementById('profile-photo-choose').addEventListener('click', () => photoInput.click());
    photoInput.addEventListener('change', async () => {
      const file = photoInput.files?.[0];
      photoInput.value = '';
      if (!file) return;
      const request = ++photoRequest;
      photoPending = true;
      save.disabled = true;
      error.hidden = true;
      photoStatus.textContent = 'Preparing photo…';
      try {
        const photo = await preparePhoto(file);
        if (request !== photoRequest) return;
        draftPhoto = photo;
        updatePhotoPreview();
      } catch (cause) {
        if (request !== photoRequest) return;
        error.textContent = cause.message;
        error.hidden = false;
      } finally { if (request === photoRequest) finishPhotoRequest(); }
    });
    photoRemove.addEventListener('click', () => {
      ++photoRequest;
      finishPhotoRequest();
      draftPhoto = '';
      photoInput.value = '';
      error.hidden = true;
      updatePhotoPreview();
    });
    dialog.addEventListener('close', () => { ++photoRequest; finishPhotoRequest(); });
    const toast = document.createElement('p');
    toast.className = 'profile-toast';
    toast.setAttribute('role', 'status');
    toast.hidden = true;
    document.body.append(toast);
    let toastTimer;

    function apply() {
      applyTheme();
      document.documentElement.lang = settings.language;
      document.querySelectorAll('.passenger-profile-name').forEach(node => { node.textContent = settings.name; node.title = settings.name; });
      document.querySelectorAll('.passenger-profile-avatar').forEach(node => renderAvatar(node, settings.photo, settings.name));
      const menu = document.getElementById('passenger-menu');
      if (menu) menu.setAttribute('aria-label', t(menu.getAttribute('aria-expanded') === 'true' ? 'Close navigation' : 'Open navigation'));
      const detailName = document.getElementById('terminal-title')?.textContent;
      const pageName = document.querySelector('.page-title')?.textContent.trim();
      document.title = `${detailName || pageName || t(document.getElementById('system-map') ? 'Map' : document.getElementById('terminal-search') ? 'Terminals & Boarding Points' : document.getElementById('terminal-content') ? 'Terminals' : 'Dashboard')} | Ala-Eh Nasaan Na?`;
      document.dispatchEvent(new CustomEvent('passenger-preferences-changed'));
    }
    applyPreferences = apply;
    apply();
    let profileReturnFocus = edit;
    function openProfile(trigger = edit) {
      profileReturnFocus = trigger;
      name.value = settings.name;
      name.setCustomValidity('');
      theme.value = settings.theme;
      ++photoRequest;
      finishPhotoRequest();
      draftPhoto = settings.photo || '';
      photoInput.value = '';
      updatePhotoPreview();
      error.hidden = true;
      dialog.showModal();
      name.focus();
    }
    edit.addEventListener('click', () => openProfile(edit));
    window.PassengerUI.openProfile = openProfile;
    dialog.addEventListener('close', () => profileReturnFocus.focus());
    document.getElementById('profile-cancel').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    name.addEventListener('input', () => { name.setCustomValidity(''); updatePhotoPreview(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (photoPending) return;
      if (!cleanName(name.value)) {
        name.setCustomValidity(t('Enter a name before saving.'));
        name.reportValidity();
        return;
      }
      if (!form.reportValidity()) return;
      try { await updateAccountName(name.value); savePreferences({ name: name.value, theme: theme.value, photo: draftPhoto }); } catch (cause) {
        error.textContent = cause.message || t('Could not save your profile name. Please try again.');
        error.hidden = false;
        return;
      }
      dialog.close();
      profileReturnFocus.focus();
      clearTimeout(toastTimer);
      toast.textContent = t('Profile updated.');
      toast.hidden = false;
      toastTimer = setTimeout(() => { toast.hidden = true; }, 3500);
    });
    window.addEventListener('storage', event => {
      if (event.key === key || event.key === null) { settings = read(); apply(); }
    });
    window.addEventListener('pageshow', () => { settings = read(); apply(); });
    syncAccountName();
  });
})();
