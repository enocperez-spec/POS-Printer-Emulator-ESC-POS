const catalogUrl = new URL('license-catalog.json', import.meta.url);

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function renderComparison(catalog) {
  const headings = ['Trial', 'Lite', 'Pro', 'Enterprise'];
  const rows = catalog.features.map((feature) => `
    <tr>
      <th scope="row">${escapeHtml(feature.name)}</th>
      ${headings.map((tier) => `<td>${escapeHtml(feature[tier])}</td>`).join('')}
    </tr>`).join('');
  return `
    <div class="license-table-scroll" tabindex="0" aria-label="Scrollable license feature comparison">
      <table class="compact-table license-comparison-table">
        <thead><tr><th scope="col">Feature</th>${headings.map((tier) => `<th scope="col">${tier} License</th>`).join('')}</tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
    <p class="license-source-note">Verified against POS Printer Emulator ${escapeHtml(catalog.sourceOfTruth.applicationVersion)} application feature flags.</p>`;
}

function renderMaintenance(catalog) {
  return `
    <table class="compact-table">
      <thead><tr><th scope="col">License</th><th scope="col">One-year renewal</th><th scope="col">Billing</th></tr></thead>
      <tbody>${Object.entries(catalog.maintenance.renewalPrices).map(([tier, price]) => `
        <tr><th scope="row">${escapeHtml(tier)}</th><td>$${Number(price).toFixed(2)}</td><td>Optional one-time payment</td></tr>`).join('')}
      </tbody>
    </table>`;
}

async function loadCatalog() {
  const response = await fetch(catalogUrl, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`License catalog unavailable (${response.status})`);
  return response.json();
}

const targets = document.querySelectorAll('[data-license-comparison], [data-maintenance-pricing]');
if (targets.length) {
  loadCatalog()
    .then((catalog) => {
      document.querySelectorAll('[data-license-comparison]').forEach((target) => {
        target.innerHTML = renderComparison(catalog);
      });
      document.querySelectorAll('[data-maintenance-pricing]').forEach((target) => {
        target.innerHTML = renderMaintenance(catalog);
      });
      document.dispatchEvent(new CustomEvent('ppe:license-catalog-ready', { detail: catalog }));
    })
    .catch(() => {
      targets.forEach((target) => {
        target.innerHTML = '<p class="callout">The license comparison is temporarily unavailable. Open the pricing page or Customer Portal for current information.</p>';
      });
    });
}
