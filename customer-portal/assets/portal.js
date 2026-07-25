(() => {
  const menuButton = document.querySelector('[data-menu-toggle]');
  const menu = document.getElementById('portal-nav');
  if (menuButton && menu) {
    menuButton.addEventListener('click', () => {
      const open = menu.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', String(open));
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm || 'Continue with this action?')) {
        event.preventDefault();
      }
    });
  });

  const copyPromotion = document.querySelector('[data-copy-promotion]');
  copyPromotion?.addEventListener('click', async () => {
    const value = copyPromotion.closest('.promotion-delivery')?.querySelector('textarea')?.value;
    if (!value) return;
    await navigator.clipboard.writeText(value);
    copyPromotion.textContent = 'Copied';
  });

  const mfaQr = document.querySelector('[data-mfa-qr]');
  if (mfaQr) {
    const provisioningUri = mfaQr.dataset.provisioningUri || '';
    const error = document.querySelector('[data-mfa-qr-error]');
    try {
      if (!provisioningUri || typeof window.QRCode !== 'function') {
        throw new Error('QR generator unavailable');
      }
      new window.QRCode(mfaQr, {
        text: provisioningUri,
        width: 200,
        height: 200,
        colorDark: '#07172d',
        colorLight: '#ffffff',
        correctLevel: window.QRCode.CorrectLevel.M,
      });
    } catch {
      if (error) error.hidden = false;
    }
  }

  const portalPage = document.querySelector('.portal-page');
  const logoutForm = document.querySelector('[data-session-logout-form]');
  if (portalPage && logoutForm) {
    const idleTimeoutMs = Number.parseInt(portalPage.dataset.sessionIdleTimeout || '600', 10) * 1000;
    const heartbeatMs = Number.parseInt(portalPage.dataset.sessionHeartbeat || '240', 10) * 1000;
    let lastActivityAt = Date.now();
    let activityRevision = 0;
    let syncedActivityRevision = 0;
    let logoutStarted = false;
    let heartbeatInFlight = false;
    let lastHeartbeatAttemptAt = Date.now();

    const recordActivity = () => {
      lastActivityAt = Date.now();
      activityRevision += 1;
      if (lastActivityAt - lastHeartbeatAttemptAt >= heartbeatMs) {
        void syncActiveSession();
      }
    };

    const redirectToExpiredLogin = () => {
      window.location.assign('/index.php?session=expired');
    };

    const logoutForInactivity = () => {
      if (logoutStarted) return;
      logoutStarted = true;
      const reason = document.createElement('input');
      reason.type = 'hidden';
      reason.name = 'reason';
      reason.value = 'idle';
      logoutForm.append(reason);
      logoutForm.requestSubmit();
    };

    const checkIdleTimeout = () => {
      if (Date.now() - lastActivityAt >= idleTimeoutMs) {
        logoutForInactivity();
      }
    };

    const syncActiveSession = async () => {
      checkIdleTimeout();
      if (logoutStarted || heartbeatInFlight || activityRevision === syncedActivityRevision) return;

      const csrf = logoutForm.querySelector('input[name="csrf"]')?.value || '';
      if (!csrf) {
        redirectToExpiredLogin();
        return;
      }

      heartbeatInFlight = true;
      lastHeartbeatAttemptAt = Date.now();
      try {
        const body = new URLSearchParams({ csrf });
        const response = await fetch('/session-heartbeat.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body,
          cache: 'no-store',
        });
        if (!response.ok) {
          redirectToExpiredLogin();
          return;
        }
        syncedActivityRevision = activityRevision;
      } catch {
        // A network failure must not silently extend the server-side session.
      } finally {
        heartbeatInFlight = false;
      }
    };

    ['pointermove', 'pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((eventName) => {
      window.addEventListener(eventName, recordActivity, { passive: true });
    });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        checkIdleTimeout();
      }
    });

    window.setInterval(checkIdleTimeout, 1000);
    window.setInterval(syncActiveSession, heartbeatMs);
  }
})();
