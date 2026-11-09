/* Account settings share the same saved preferences as the profile editor. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const get = id => document.getElementById(id);
    const form = get('passenger-settings');
    if (!form) return;
    const ui = window.PassengerUI, t = text => ui.t(text);
    const name = get('settings-name'), theme = get('settings-theme'), language = get('settings-language');
    const error = get('settings-error'), status = get('settings-status');
    let saving = false, edited = false;
    function fill() {
      const saved = ui.getPreferences();
      name.value = saved.name || '';
      name.setCustomValidity('');
      theme.value = saved.theme;
      language.value = saved.language;
      error.hidden = true;
      status.textContent = '';
      edited = false;
    }
    fill();
    ui.syncAccountName().then(() => { if (!edited && !saving) fill(); });
    form.addEventListener('input', () => {
      edited = true;
      name.setCustomValidity('');
      error.hidden = true;
      status.textContent = '';
    });
    async function saveName() {
      if (saving) return;
      error.hidden = true; status.textContent = '';
      if (!name.value.trim()) {
        name.setCustomValidity(t('Enter a name before saving.'));
        name.reportValidity(); return false;
      }
      if (!form.reportValidity()) return false;
      saving = true; form.setAttribute('aria-busy', 'true');
      try {
        await ui.updateAccountName(name.value);
        ui.savePreferences({ name: name.value });
        edited = false;
        status.textContent = t('Name saved.');
        return true;
      } catch (cause) {
        error.textContent = t(cause.message || 'Could not save your settings. Please try again.');
        error.hidden = false;
        return false;
      } finally {
        saving = false; form.setAttribute('aria-busy', 'false');
      }
    }
    form.addEventListener('submit', event => {
      event.preventDefault();
      saveName();
    });
    name.addEventListener('change', saveName);
    function savePreference(field, message) {
      if (saving) return;
      error.hidden = true;
      ui.savePreferences({ [field]: field === 'theme' ? theme.value : language.value });
      edited = false;
      status.textContent = t(message);
    }
    theme.addEventListener('change', () => savePreference('theme', 'Theme updated.'));
    language.addEventListener('change', () => savePreference('language', 'Language updated.'));
    document.addEventListener('passenger-preferences-changed', () => { if (!saving && !edited) fill(); });
    const profile = get('settings-open-profile');
    profile?.addEventListener('click', () => ui.openProfile(profile));

    const passwordForm = get('commuter-password-form');
    const current = get('current-password'), next = get('new-password'), confirm = get('confirm-password');
    const passwordError = get('password-error'), passwordStatus = get('password-status'), passwordSave = get('settings-password-save');
    let changingPassword = false;
    passwordForm.addEventListener('input', () => {
      next.setCustomValidity(''); confirm.setCustomValidity('');
      passwordError.hidden = true; passwordStatus.textContent = '';
    });
    passwordForm.addEventListener('submit', async event => {
      event.preventDefault();
      if (changingPassword) return;
      passwordError.hidden = true; passwordStatus.textContent = '';
      const bytes = new TextEncoder().encode(next.value).length;
      next.setCustomValidity(bytes < 12 || bytes > 72 ? t('Use a new password between 12 and 72 bytes.') : '');
      confirm.setCustomValidity(next.value !== confirm.value ? t('The new passwords do not match.') : '');
      if (!passwordForm.reportValidity()) return;
      changingPassword = true; passwordSave.disabled = true; passwordForm.setAttribute('aria-busy', 'true');
      const passwords = { current_password: current.value, new_password: next.value, confirm_password: confirm.value };
      try {
        const session = await fetch('api/auth.php?action=session', { credentials: 'same-origin', cache: 'no-store' });
        const account = await session.json();
        if (!session.ok || !account.csrf_token) throw new Error(account.error || 'Please sign in again.');
        const response = await fetch('api/auth.php?action=change-password', {
          method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...passwords, csrf_token: account.csrf_token }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not change your password. Please try again.');
        passwordForm.reset();
        passwordStatus.textContent = t(result.message || 'Your password was changed successfully.');
      } catch (cause) {
        passwordError.textContent = t(cause.message || 'Could not change your password. Please try again.');
        passwordError.hidden = false;
      } finally {
        changingPassword = false; passwordSave.disabled = false; passwordForm.setAttribute('aria-busy', 'false');
      }
    });
  });
})();
