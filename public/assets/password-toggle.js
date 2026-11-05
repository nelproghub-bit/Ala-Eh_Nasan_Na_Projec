document.querySelectorAll('.password-visibility-toggle').forEach((button) => {
  const field = button.closest('.password-field');
  const input = field?.querySelector('input[type="password"], input[type="text"]');
  const icon = button.querySelector('svg');
  const slash = icon?.querySelector('[data-eye-slash]');

  if (!input || !icon || !slash) {
    button.hidden = true;
    return;
  }

  button.addEventListener('click', () => {
    const showPassword = input.type === 'password';
    input.type = showPassword ? 'text' : 'password';
    button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    button.setAttribute('aria-pressed', String(showPassword));
  });
});
