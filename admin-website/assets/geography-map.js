import worldMap from './maps/world.js';
import usaMap from './maps/usa.js';

const container = document.querySelector('#geography-map');
const dataNode = document.querySelector('#geography-data');

if (container && dataNode) {
  try {
    const dashboard = JSON.parse(dataNode.textContent || '{}');
    const map = dashboard.view === 'usa' ? usaMap : worldMap;
    const values = new Map((dashboard.rows || []).map((row) => [String(row.code).toLowerCase(), Number(row.value) || 0]));
    const maximum = Math.max(0, ...values.values());
    const svg = container.querySelector('svg');
    const detail = container.querySelector('#geography-map-detail');
    const metricName = metricLabel(dashboard.metric);
    const namespace = 'http://www.w3.org/2000/svg';

    svg.setAttribute('viewBox', map.viewBox);
    svg.replaceChildren();

    const title = document.createElementNS(namespace, 'title');
    title.id = 'geography-map-title';
    title.textContent = map.label;
    const description = document.createElementNS(namespace, 'desc');
    description.id = 'geography-map-description';
    description.textContent = `Interactive map of ${metricName}. Use Tab to move between regions and Enter or Space to select one. Exact values are also available in the regional table.`;
    svg.append(title, description);

    let selectedPath = null;
    for (const location of map.locations) {
      const code = String(location.id).toLowerCase();
      const value = values.get(code) || 0;
      const path = document.createElementNS(namespace, 'path');
      const label = `${location.name}: ${formatValue(value)} ${metricName}`;
      path.setAttribute('d', location.path);
      path.setAttribute('data-region-code', code);
      path.setAttribute('data-value', String(value));
      path.setAttribute('class', `level-${intensity(value, maximum)}`);
      path.setAttribute('tabindex', '0');
      path.setAttribute('role', 'button');
      path.setAttribute('aria-label', label);

      const tooltip = document.createElementNS(namespace, 'title');
      tooltip.textContent = label;
      path.append(tooltip);

      const select = () => {
        selectedPath?.classList.remove('selected');
        path.classList.add('selected');
        selectedPath = path;
        detail.textContent = label;
      };
      path.addEventListener('click', select);
      path.addEventListener('focus', select);
      path.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          select();
        }
      });
      svg.append(path);
    }

    const names = new Map(map.locations.map((location) => [String(location.id).toLowerCase(), location.name]));
    document.querySelectorAll('#geography-rows tr[data-region-code]').forEach((row) => {
      const code = String(row.dataset.regionCode || '').toLowerCase();
      const name = row.querySelector('[data-geography-name]');
      if (name && names.has(code)) name.textContent = names.get(code);
    });
  } catch (error) {
    container.querySelector('svg')?.remove();
    const detail = container.querySelector('#geography-map-detail');
    if (detail) detail.textContent = 'The map could not be displayed. The complete regional table remains available.';
    console.error('Geography map failed to initialize.', error);
  }
}

function intensity(value, maximum) {
  if (value <= 0 || maximum <= 0) return 0;
  return Math.max(1, Math.min(5, Math.ceil(Math.sqrt(value / maximum) * 5)));
}

function formatValue(value) {
  return new Intl.NumberFormat().format(value);
}

function metricLabel(metric) {
  switch (metric) {
    case 'downloads': return 'download starts';
    case 'launches': return 'application launches';
    case 'print_jobs': return 'emulated print jobs';
    default: return 'active installations';
  }
}
