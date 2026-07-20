const body = document.body;
const form = document.querySelector('#purchase-form');
const errorBox = document.querySelector('#form-error');
const tierInputs = [...document.querySelectorAll('input[name="licenseTier"]')];
const selectedTierPill = document.querySelector('#selected-tier-pill');
const selectedPrice = document.querySelector('#selected-price');
const selectedCurrency = document.querySelector('#selected-currency');

const showError = (message) => {
  errorBox.textContent = message;
  errorBox.hidden = false;
};

const createOrder = async () => {
  const response = await fetch('api/create-order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      customerName: form.customerName.value,
      email: form.email.value,
      licenseTier: form.licenseTier.value,
      product: body.dataset.product || 'license',
      licenseId: form.licenseId?.value || '',
    }),
  });
  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.error || 'Could not start checkout.');
  }
  if (typeof data.orderId !== 'string' || data.orderId.trim() === '') {
    throw new Error('PayPal did not return a valid order ID.');
  }
  return data.orderId;
};

const updateSelectedTier = () => {
  const selected = tierInputs.find((input) => input.checked);
  if (!selected) return;
  const currency = selected.dataset.currency || 'USD';
  const renewal = body.dataset.product === 'maintenance';
  if (selectedTierPill) selectedTierPill.textContent = `${selected.value} ${renewal ? 'Maintenance' : 'License'}`;
  if (selectedPrice) selectedPrice.textContent = currency === 'USD' ? `$${Number(selected.dataset.price).toFixed(2)}` : selected.dataset.price;
  if (selectedCurrency) selectedCurrency.textContent = `${currency} · ${renewal ? 'one-time renewal' : 'one-time'}`;
};

tierInputs.forEach((input) => input.addEventListener('change', updateSelectedTier));
updateSelectedTier();

if (body.dataset.checkoutReady === 'true') {
  const waitForPayPal = async () => {
    for (let i = 0; i < 100 && !window.paypal; i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    if (!window.paypal) {
      throw new Error('PayPal checkout did not load.');
    }
    return window.paypal;
  };

  try {
    const paypal = await waitForPayPal();
    const sdk = await paypal.createInstance({
      clientId: body.dataset.clientId,
      components: ['paypal-payments'],
      pageType: 'checkout',
    });
    const methods = await sdk.findEligibleMethods({
      currencyCode: body.dataset.currency,
    });
    const button = document.querySelector('#paypal-button');
    if (!methods.isEligible('paypal')) {
      throw new Error('PayPal is not currently available.');
    }

    const session = sdk.createPayPalOneTimePaymentSession({
      onApprove: async ({ orderId }) => {
        const response = await fetch('api/capture-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ orderId }),
        });
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || 'Payment verification failed.');
        }
        window.location.href = `success.php?order=${encodeURIComponent(data.publicId)}`;
      },
      onCancel: () => showError('Checkout was canceled. No payment was taken.'),
      onError: (error) => showError(error?.message || 'PayPal could not complete checkout. Please try again.'),
    });

    button.addEventListener('click', async () => {
      errorBox.hidden = true;
      if (!form.reportValidity()) {
        return;
      }

      try {
        const orderPromise = createOrder();
        await session.start({ presentationMode: 'auto' }, orderPromise);
      } catch (error) {
        showError(error?.message || 'Could not start checkout.');
      }
    });
  } catch (error) {
    showError(error?.message || 'PayPal checkout did not load.');
  }
}
