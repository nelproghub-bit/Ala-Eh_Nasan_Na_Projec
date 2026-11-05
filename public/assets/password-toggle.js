document.querySelectorAll('.password-visibility-toggle').forEach((button) => {
  const field = button.closest('.password-field');
  const input = field?.querySelector('input[type="password"], input[type="text"]');
  const icons = button.querySelectorAll('svg');

  if (!input || icons.length < 2) {
    button.hidden = true;
    return;
  }

  button.addEventListener('click', () => {
    const showPassword = input.type === 'password';
    input.type = showPassword ? 'text' : 'password';
    button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    button.setAttribute('aria-pressed', String(showPassword));
    icons[0].hidden = showPassword;
    icons[1].hidden = !showPassword;
  });
});
