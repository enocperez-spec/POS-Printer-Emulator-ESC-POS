(() => {
  const root = document.querySelector('[data-auth-root]');
  if (!root) return;

  const links = Array.from(root.querySelectorAll('[data-auth-mode]'));
  const panels = Array.from(root.querySelectorAll('[data-auth-panel]'));
  const allowed = new Set(panels.map((panel) => panel.dataset.authPanel));

  const activate = (requested, updateHash = false) => {
    const mode = allowed.has(requested) ? requested : 'sign-in';
    root.dataset.activePanel = mode;

    panels.forEach((panel) => {
      const active = panel.dataset.authPanel === mode;
      panel.hidden = !active;
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });

    links.forEach((link) => {
      const active = link.dataset.authMode === mode;
      link.classList.toggle('active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });

    if (updateHash) {
      history.replaceState(null, '', `#${mode}`);
    }
  };

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      activate(link.dataset.authMode, true);
      const activePanel = root.querySelector(`[data-auth-panel="${link.dataset.authMode}"]`);
      const heading = activePanel?.querySelector('h1, h2');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
    });
  });

  document.documentElement.classList.add('auth-tabs-ready');
  const requested = location.hash.slice(1);
  activate(allowed.has(requested) ? requested : root.dataset.initialPanel);
})();
