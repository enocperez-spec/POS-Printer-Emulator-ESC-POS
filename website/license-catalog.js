const catalogUrl = new URL('license-catalog.json', import.meta.url);
const managedPricingUrl = 'https://buy.posprinteremulator.com/api/public-pricing.php';

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function formatPurchasePrice(tier, details) {
  if (tier === 'Trial') {
    return {
      price: 'Free',
      billing: 'Start testing before you buy',
    };
  }

  const price = Number(details.purchasePrice);
  return {
    price: Number.isFinite(price) ? `$${price.toFixed(2)}` : 'View current price',
    billing: 'USD · one-time purchase',
  };
}

function renderPurchasePrices(catalog) {
  const tiers = ['Trial', 'Lite', 'Pro', 'Enterprise'];
  return `
    <div class="purchase-price-grid">
      ${tiers.map((tier) => {
        const details = catalog.licenses[tier];
        const price = formatPurchasePrice(tier, details);
        const listenerText = details.listenerLimit === 1
          ? '1 printer listener'
          : `Up to ${details.listenerLimit} printer listeners`;
        return `
          <article class="purchase-price-card${tier === 'Pro' ? ' is-featured' : ''}">
            ${tier === 'Pro' ? '<span class="purchase-price-badge">Popular choice</span>' : ''}
            <h3>${escapeHtml(tier)}</h3>
            <p class="purchase-price-listeners">${escapeHtml(listenerText)}</p>
            <strong>${escapeHtml(price.price)}</strong>
            <small>${escapeHtml(price.billing)}</small>
          </article>`;
      }).join('')}
    </div>
    <p class="purchase-price-note">Every paid license includes one year of Maintenance and Support. Optional renewal pricing is separate from the software purchase price.</p>
    <p class="pricing-sync-note">${catalog.pricingSource === 'admin'
      ? 'Prices are synchronized automatically with the Purchase Pricing tools in the Admin Portal.'
      : 'Catalog prices are shown because the managed pricing service is temporarily unavailable.'}</p>`;
}

function renderComparison(catalog, includePurchasePricing = false) {
  const headings = ['Trial', 'Lite', 'Pro', 'Enterprise'];
  const purchasePriceFeature = {
    name: 'One-time license purchase price',
    ...Object.fromEntries(
      headings.map((tier) => [
        tier,
        formatPurchasePrice(tier, catalog.licenses[tier]).price,
      ]),
    ),
  };
  const maintenancePriceFeature = {
    name: 'Optional annual Maintenance and Support renewal',
    Trial: 'Not applicable',
    ...Object.fromEntries(
      headings
        .filter((tier) => tier !== 'Trial')
        .map((tier) => [
          tier,
          `$${Number(catalog.maintenance.renewalPrices[tier]).toFixed(2)}`,
        ]),
    ),
  };
  const catalogFeatures = catalog.features.map((feature) => (
    feature.name === maintenancePriceFeature.name
      ? maintenancePriceFeature
      : feature
  ));
  const features = includePurchasePricing
    ? [purchasePriceFeature, ...catalogFeatures]
    : catalogFeatures;
  const rows = features.map((feature) => `
    <tr${feature.name === purchasePriceFeature.name ? ' class="license-purchase-price-row"' : ''}>
      <th scope="row">${escapeHtml(feature.name)}</th>
      ${headings.map((tier) => `<td>${escapeHtml(feature[tier])}</td>`).join('')}
    </tr>`).join('');
  return `
    <div class="license-table-scroll" aria-label="License feature comparison">
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
  const catalog = await response.json();
  catalog.pricingSource = 'catalog';

  try {
    const pricingResponse = await fetch(managedPricingUrl, {
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!pricingResponse.ok) {
      return catalog;
    }

    const managedPricing = await pricingResponse.json();
    ['Lite', 'Pro', 'Enterprise'].forEach((tier) => {
      const purchasePrice = Number(managedPricing.licenseOffers?.[tier]?.price);
      const renewalPrice = Number(managedPricing.maintenanceOffers?.[tier]?.price);
      if (Number.isFinite(purchasePrice) && purchasePrice >= 0.5) {
        catalog.licenses[tier].purchasePrice = purchasePrice;
      }
      if (Number.isFinite(renewalPrice) && renewalPrice >= 0.5) {
        catalog.maintenance.renewalPrices[tier] = renewalPrice;
      }
    });
    catalog.pricingSource = 'admin';
  } catch {
    // The verified catalog remains a safe fallback when the pricing service is unavailable.
  }

  return catalog;
}

const targets = document.querySelectorAll('[data-license-purchase-pricing], [data-license-comparison], [data-maintenance-pricing]');
if (targets.length) {
  loadCatalog()
    .then((catalog) => {
      document.querySelectorAll('[data-license-purchase-pricing]').forEach((target) => {
        target.innerHTML = renderPurchasePrices(catalog);
      });
      document.querySelectorAll('[data-license-comparison]').forEach((target) => {
        target.innerHTML = renderComparison(
          catalog,
          target.classList.contains('pricing-license-comparison'),
        );
      });
      document.querySelectorAll('[data-maintenance-pricing]').forEach((target) => {
        target.innerHTML = renderMaintenance(catalog);
      });
      document.querySelectorAll('[data-license-price]').forEach((target) => {
        const tier = target.getAttribute('data-license-price');
        if (tier && catalog.licenses[tier]) {
          target.textContent = formatPurchasePrice(tier, catalog.licenses[tier]).price;
        }
      });
      document.dispatchEvent(new CustomEvent('ppe:license-catalog-ready', { detail: catalog }));
    })
    .catch(() => {
      targets.forEach((target) => {
        target.innerHTML = '<p class="callout">The license comparison is temporarily unavailable. Open the pricing page or Customer Portal for current information.</p>';
      });
    });
}
