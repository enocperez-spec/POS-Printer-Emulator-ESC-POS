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
})();
