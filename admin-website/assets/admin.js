const search = document.querySelector('#customer-search');
const licenseFilter = document.querySelector('#license-filter');
const rows = [...document.querySelectorAll('#installation-rows tr[data-license]')];
const visibleCount = document.querySelector('#visible-count');
const dateRange = document.querySelector('#date-range');

dateRange?.addEventListener('change', () => dateRange.form?.requestSubmit());

function filterRows() {
  const query = (search?.value || '').trim().toLowerCase();
  const license = licenseFilter?.value || 'all';
  let visible = 0;
  rows.forEach((row) => {
    const matchesQuery = !query || row.textContent.toLowerCase().includes(query);
    const matchesLicense = license === 'all' || row.dataset.license === license;
    row.hidden = !(matchesQuery && matchesLicense);
    if (!row.hidden) visible += 1;
  });
  if (visibleCount) visibleCount.textContent = `Showing ${visible} installation${visible === 1 ? '' : 's'}`;
}

search?.addEventListener('input', filterRows);
licenseFilter?.addEventListener('change', filterRows);
document.querySelector('[data-license-link]')?.addEventListener('click', () => {
  if (licenseFilter) licenseFilter.value = 'Pro';
  filterRows();
});

const svg = document.querySelector('#usage-chart');
const dataNode = document.querySelector('#usage-data');
if (svg && dataNode) {
  const data = JSON.parse(dataNode.textContent || '[]');
  const width = 760, height = 260, left = 42, right = 18, top = 18, bottom = 34;
  const innerWidth = width - left - right, innerHeight = height - top - bottom;
  const maximum = Math.max(1, ...data.flatMap((point) => [point.launches, point.jobs]));
  const point = (value, index) => `${left + (index / Math.max(1, data.length - 1)) * innerWidth},${top + innerHeight - (value / maximum) * innerHeight}`;
  const ns = 'http://www.w3.org/2000/svg';
  for (let line = 0; line <= 4; line += 1) {
    const y = top + (line / 4) * innerHeight;
    const grid = document.createElementNS(ns, 'line');
    grid.setAttribute('x1', left); grid.setAttribute('x2', width - right); grid.setAttribute('y1', y); grid.setAttribute('y2', y); grid.setAttribute('class', 'grid-line');
    svg.append(grid);
    const label = document.createElementNS(ns, 'text');
    const axisValue = maximum * (1 - line / 4);
    label.setAttribute('x', left - 8); label.setAttribute('y', y + 4); label.setAttribute('text-anchor', 'end'); label.textContent = maximum <= 4 ? axisValue.toFixed(2).replace(/\.00$/, '') : Math.round(axisValue).toLocaleString();
    svg.append(label);
  }
  [['launches', 'launch-line'], ['jobs', 'job-line']].forEach(([key, className]) => {
    const path = document.createElementNS(ns, 'polyline');
    path.setAttribute('points', data.map((entry, index) => point(entry[key], index)).join(' '));
    path.setAttribute('class', className); svg.append(path);
  });
  const labels = data.length > 12 ? 5 : Math.min(data.length, 7);
  for (let index = 0; index < labels; index += 1) {
    const dataIndex = Math.round((index / Math.max(1, labels - 1)) * (data.length - 1));
    const label = document.createElementNS(ns, 'text');
    label.setAttribute('x', left + (dataIndex / Math.max(1, data.length - 1)) * innerWidth);
    label.setAttribute('y', height - 8); label.setAttribute('text-anchor', index === 0 ? 'start' : index === labels - 1 ? 'end' : 'middle');
    label.textContent = new Date(`${data[dataIndex].date}T00:00:00Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' }); svg.append(label);
  }
}
