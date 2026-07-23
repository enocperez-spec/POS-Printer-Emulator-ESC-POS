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
})();
