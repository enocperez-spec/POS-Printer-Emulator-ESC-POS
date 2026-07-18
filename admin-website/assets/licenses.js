(() => {
  const copy = async (value, button) => {
    await navigator.clipboard.writeText(value);
    const original = button.textContent;
    button.textContent = 'Copied';
    setTimeout(() => { button.textContent = original; }, 1400);
  };

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', () => {
      const target = document.getElementById(button.dataset.copyTarget);
      if (target) copy(target.value, button).catch(() => target.select());
    });
  });
  document.querySelectorAll('[data-key]').forEach((button) => {
    button.addEventListener('click', () => copy(button.dataset.key, button).catch(() => {}));
  });

  const search = document.getElementById('license-search');
  const filter = document.getElementById('status-filter');
  const rows = [...document.querySelectorAll('#license-rows tr[data-status]')];
  const count = document.getElementById('license-count');
  const update = () => {
    const query = (search?.value || '').trim().toLowerCase();
    const status = filter?.value || 'all';
    let visible = 0;
    rows.forEach((row) => {
      const show = (!query || row.textContent.toLowerCase().includes(query)) && (status === 'all' || row.dataset.status === status);
      row.hidden = !show;
      if (show) visible += 1;
    });
    if (count) count.textContent = `Showing ${visible} licenses`;
  };
  search?.addEventListener('input', update);
  filter?.addEventListener('change', update);
})();
