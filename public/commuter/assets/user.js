'use strict';

const authEndpoint = 'api/auth.php';
const liveUpdateEndpoint = 'api/live-update-token.php';
const loginForm = document.getElementById('passenger-login');
const logoutButton = document.getElementById('passenger-logout');
const passengerText = text => window.PassengerUI?.t(text) ?? text;
const rememberAccountName = account => {
  const name = typeof account?.full_name === 'string' ? account.full_name.trim() : '';
  if (name) window.PassengerUI?.savePreferences({ name });
};

(() => {
  const interval = 2000;
  let token = null;
  let requestInFlight = false;
  let refreshPending = false;

  const isSafeToRefresh = () => {
    if (document.visibilityState !== 'visible') return false;
    if (document.querySelector('dialog[open], [role="dialog"][aria-hidden="false"]')) return false;
    const active = document.activeElement;
    return !active || !/^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName);
  };

  const refreshWhenSafe = () => {
    if (!refreshPending || !isSafeToRefresh()) return;
    refreshPending = false;
    window.location.reload();
  };

  const checkForChanges = async () => {
    if (requestInFlight || document.visibilityState !== 'visible') return;
    requestInFlight = true;
    try {
      const response = await fetch(`${liveUpdateEndpoint}?_=${Date.now()}`, {
        credentials: 'same-origin', cache: 'no-store',
      });
      if (response.status === 401) return;
      if (!response.ok) throw new Error('Live update check failed.');
      const update = await response.json();
      if (typeof update.token !== 'string') return;
      if (token !== null && token !== update.token) {
        refreshPending = true;
        window.dispatchEvent(new CustomEvent('system:updated'));
        refreshWhenSafe();
      }
      token = update.token;
    } catch (error) {
      console.debug('Live updates are temporarily unavailable.', error);
    } finally {
      requestInFlight = false;
    }
  };

  ['focusin', 'change', 'blur'].forEach(eventName => document.addEventListener(eventName, refreshWhenSafe));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      checkForChanges();
      refreshWhenSafe();
    }
  });
  checkForChanges();
  window.setInterval(checkForChanges, interval);
})();

if (loginForm) {
  const resumePassenger = async () => {
    try {
      const response = await fetch(`${authEndpoint}?action=session`, { credentials: 'same-origin', cache: 'no-store' });
      if (response.ok) {
        const session = await response.json();
        rememberAccountName(session.account);
        window.location.replace('user-dashboard.html');
      }
    } catch { /* The login form remains usable when the server is unavailable. */ }
  };
  resumePassenger();
  window.addEventListener('pageshow', resumePassenger);
  const registerForm = document.getElementById('passenger-register');
  const registrationPassword = document.getElementById('register-password');
  const confirmation = document.getElementById('confirm-password');
  const registrationResult = document.getElementById('registration-result');
  const headerDropdowns = document.querySelectorAll('.header-portal-nav details');
  const setRegistrationMode = (registering) => {
    document.querySelector('.form-heading .form-kicker').textContent = 'PASSENGER PORTAL';
    loginForm.hidden = registering;
    registerForm.hidden = !registering;
    document.getElementById('signup-prompt').hidden = registering;
    document.getElementById('signin-prompt').hidden = !registering;
    document.getElementById('demo-description').hidden = registering;
    document.getElementById('registration-note').hidden = !registering;
    document.getElementById('login-error').hidden = true;
    registrationResult.hidden = true;
    document.getElementById('login-title').textContent = registering ? 'Create your account.' : 'Welcome back.';
    document.querySelector('.form-heading p').textContent = registering ? 'Your next journey starts here.' : 'Sign in and get ready for your next journey.';
    document.title = `${registering ? 'Create account' : 'Passenger login'} | Ala-Eh Nasaan Na?`;
    document.getElementById(registering ? 'register-name' : 'email').focus();
  };
  const alignDropdownArrow = (dropdown) => {
    const trigger = dropdown.querySelector('summary').getBoundingClientRect();
    const popover = dropdown.querySelector('.dropdown-popover');
    const panel = popover.getBoundingClientRect();
    const arrowX = Math.max(16, Math.min(panel.width - 16, trigger.left + trigger.width / 2 - panel.left));
    popover.style.setProperty('--dropdown-arrow-x', `${arrowX}px`);
  };
  const alignOpenDropdowns = () => {
    headerDropdowns.forEach((dropdown) => {
      if (dropdown.open) alignDropdownArrow(dropdown);
    });
  };
  window.addEventListener('resize', alignOpenDropdowns);
  if (document.fonts) document.fonts.ready.then(alignOpenDropdowns);
  headerDropdowns.forEach((dropdown) => {
    dropdown.addEventListener('toggle', () => {
      if (dropdown.open) {
        alignDropdownArrow(dropdown);
        headerDropdowns.forEach((other) => {
          if (other !== dropdown) other.open = false;
        });
      }
    });
  });
  document.addEventListener('click', (event) => {
    headerDropdowns.forEach((dropdown) => {
      if (!dropdown.contains(event.target)) dropdown.open = false;
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    headerDropdowns.forEach((dropdown) => {
      if (dropdown.open) {
        dropdown.open = false;
        dropdown.querySelector('summary').focus();
      }
    });
  });
  document.getElementById('open-register').addEventListener('click', () => setRegistrationMode(true));
  document.getElementById('open-login').addEventListener('click', () => {
    registerForm.reset();
    confirmation.setCustomValidity('');
    setRegistrationMode(false);
  });
  const validateConfirmation = () => {
    confirmation.setCustomValidity(confirmation.value !== registrationPassword.value ? 'Passwords must match.' : '');
  };
  confirmation.addEventListener('input', validateConfirmation);
  registrationPassword.addEventListener('input', validateConfirmation);
  registerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    validateConfirmation();
    if (!registerForm.reportValidity()) return;
    try {
      const response = await fetch(`${authEndpoint}?action=register`, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ full_name: document.getElementById('register-name').value, email: document.getElementById('register-email').value, password: registrationPassword.value }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Account could not be created.');
      registerForm.reset();
      setRegistrationMode(false);
      registrationResult.textContent = result.message || 'Account created. Choose Sign in to continue.';
      registrationResult.hidden = false;
    } catch (failure) {
      registrationResult.textContent = failure.message || 'Account could not be created. Please try again.';
      registrationResult.hidden = false;
    }
  });
  const password = document.getElementById('password');
  const toggle = document.getElementById('toggle-password');
  toggle.addEventListener('click', () => {
    const visible = password.type === 'password';
    password.type = visible ? 'text' : 'password';
    toggle.textContent = visible ? 'Hide' : 'Show';
    toggle.setAttribute('aria-pressed', String(visible));
  });
  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = document.getElementById('login-error');
    try {
      const response = await fetch(`${authEndpoint}?action=login`, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: document.getElementById('email').value, password: password.value }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Invalid email or password.');
      password.value = '';
      rememberAccountName(result.account);
      window.location.assign(result.portal === 'admin' ? '../admin-dashboard.php' : 'user-dashboard.html');
    } catch (failure) {
      error.textContent = failure.message || 'Account service is temporarily unavailable. Please try again.';
      error.hidden = false;
    }
  });
}

if (logoutButton) {
  let presenceStarted = false;
  const startPresenceTracking = csrfToken => {
    if (presenceStarted || !csrfToken) return;
    presenceStarted = true;
    let lastHeartbeat = 0;
    let heartbeatInFlight = false;
    const heartbeat = async () => {
      if (heartbeatInFlight || document.visibilityState !== 'visible') return;
      heartbeatInFlight = true;
      lastHeartbeat = Date.now();
      try {
        const response = await fetch(`${authEndpoint}?action=presence`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrfToken }),
        });
        if (response.status === 401) window.location.replace('user-login.html');
        else if (!response.ok) lastHeartbeat = 0;
      } catch (error) {
        lastHeartbeat = 0;
        console.warn('Commuter activity could not be recorded.', error);
      } finally {
        heartbeatInFlight = false;
      }
    };
    const markInteraction = () => {
      const now = Date.now();
      if (now - lastHeartbeat >= 15000) heartbeat();
    };
    ['pointerdown', 'keydown', 'scroll', 'touchstart', 'input'].forEach(eventName => {
      document.addEventListener(eventName, markInteraction, { passive: true });
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') markInteraction();
    });
    heartbeat();
  };
  const checkPassengerSession = async () => {
    try {
      const response = await fetch(`${authEndpoint}?action=session`, { credentials: 'same-origin', cache: 'no-store' });
      if (response.ok) {
        const session = await response.json();
        startPresenceTracking(session.csrf_token);
        return;
      }
    } catch { /* Return to login when the account service is unavailable. */ }
    window.location.replace('user-login.html');
  };
  checkPassengerSession();
  window.addEventListener('pageshow', checkPassengerSession);
  const logOut = async () => {
    try { await fetch(`${authEndpoint}?action=logout`, { method: 'POST', credentials: 'same-origin' }); } catch { /* Navigation still works. */ }
    window.location.replace('user-login.html');
  };
  logoutButton.addEventListener('click', logOut);
  document.getElementById('settings-logout')?.addEventListener('click', logOut);
  const menu = document.getElementById('passenger-menu');
  const sidebar = document.getElementById('passenger-sidebar');
  const backdrop = document.getElementById('passenger-backdrop');
  const closeButton = document.getElementById('passenger-menu-close');
  const pageContent = document.querySelector('.app-content');
  const closeMenu = (restoreFocus = false) => {
    sidebar.classList.remove('mobile-open');
    document.body.classList.remove('navigation-open');
    sidebar.inert = true;
    sidebar.setAttribute('aria-hidden', 'true');
    if (pageContent) pageContent.inert = false;
    if (backdrop) backdrop.hidden = true;
    menu.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-label', passengerText('Open navigation'));
    if (restoreFocus) menu.focus();
  };
  const openMenu = () => {
    sidebar.inert = false;
    sidebar.setAttribute('aria-hidden', 'false');
    sidebar.classList.add('mobile-open');
    document.body.classList.add('navigation-open');
    if (pageContent) pageContent.inert = true;
    if (backdrop) backdrop.hidden = false;
    menu.setAttribute('aria-expanded', 'true');
    menu.setAttribute('aria-label', passengerText('Close navigation'));
    (sidebar.querySelector('[aria-current="page"]') || closeButton)?.focus();
  };
  closeMenu();
  menu.addEventListener('click', () => {
    if (sidebar.classList.contains('mobile-open')) closeMenu(true);
    else openMenu();
  });
  closeButton?.addEventListener('click', () => closeMenu(true));
  backdrop?.addEventListener('click', () => closeMenu(true));
  sidebar.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => closeMenu());
  });
  document.addEventListener('keydown', (event) => {
    if (!sidebar.classList.contains('mobile-open') || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') {
      event.preventDefault(); closeMenu(true);
    } else if (event.key === 'Tab') {
      const items = [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]')];
      const first = items[0], last = items.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  window.addEventListener('pageshow', event => { if (event.persisted) closeMenu(); });
}

const backButton = document.getElementById('passenger-back');
const currentLocation = globalThis.location;
const backQuery = currentLocation?.search?.slice(1) || '';
const routeParameter = backQuery.match(/(?:^|&)route=([^&]*)/);
const terminalParameter = backQuery.match(/(?:^|&)terminal=([^&]*)/);
const cameFromTerminalRoute = currentLocation?.pathname?.endsWith('/user-map.html')
  && Number.isSafeInteger(Number(routeParameter?.[1]))
  && Number.isSafeInteger(Number(terminalParameter?.[1]))
  && /(?:^|&)focus=terminal(?:&|$)/.test(backQuery);
const returnTerminalId = Number(terminalParameter?.[1]);
const routeReturnUrl = Number.isSafeInteger(returnTerminalId) && returnTerminalId > 0
  ? `user-terminal-details.html?id=${encodeURIComponent(returnTerminalId)}`
  : 'user-terminals.html';
if (backButton) {
  backButton.hidden = !cameFromTerminalRoute;
  backButton.addEventListener('click', () => {
    if (history.length > 1) {
      history.back();
      return;
    }
    location.assign(routeReturnUrl);
  });
}
