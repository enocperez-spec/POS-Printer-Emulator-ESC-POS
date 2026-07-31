const body = document.body;
const errorBox = document.querySelector('#form-error');
const button = document.querySelector('#paypal-button');
const restartLink = document.querySelector('#checkout-restart');

const showError = (message, requiresRestart = false) => {
  errorBox.textContent = message;
  errorBox.hidden = false;
  if (restartLink) restartLink.hidden = !requiresRestart;
  if (requiresRestart && button) {
    button.setAttribute('disabled', '');
    button.setAttribute('aria-disabled', 'true');
  }
};

const post = async (path, payload) => {
  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json();
  if (!response.ok) {
    const error = new Error(data.error || 'The secure checkout request failed.');
    error.status = response.status;
    throw error;
  }
  return data;
};

const waitForPayPal = async () => {
  for (let attempt = 0; attempt < 100 && !window.paypal; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  if (!window.paypal) throw new Error('PayPal checkout did not load.');
  return window.paypal;
};

try {
  const paypal = await waitForPayPal();
  const sdk = await paypal.createInstance({
    clientId: body.dataset.clientId,
    components: ['paypal-payments'],
    pageType: 'checkout',
  });
  const methods = await sdk.findEligibleMethods({ currencyCode: body.dataset.currency });
  if (!methods.isEligible('paypal')) throw new Error('PayPal is not currently available.');

  const session = sdk.createPayPalOneTimePaymentSession({
    onApprove: async ({ orderId }) => {
      const result = await post('/api/capture-portal-order.php', {
        checkoutToken: body.dataset.checkoutToken,
        orderId,
      });
      window.location.href = `/success.php?order=${encodeURIComponent(result.publicId)}`;
    },
    onCancel: () => showError('Checkout was canceled. No payment was taken.'),
    onError: (error) => showError(error?.message || 'PayPal could not complete checkout. Please try again.'),
  });

  button.addEventListener('click', async () => {
    errorBox.hidden = true;
    try {
      const orderIdPromise = post('/api/create-portal-order.php', {
        checkoutToken: body.dataset.checkoutToken,
      }).then((result) => {
        if (typeof result.orderId !== 'string' || result.orderId.length === 0) {
          throw new Error('PayPal did not return a valid order ID.');
        }
        return { orderId: result.orderId };
      });
      await session.start({ presentationMode: 'modal' }, orderIdPromise);
    } catch (error) {
      showError(error?.message || 'Could not start checkout.', error?.status === 410);
    }
  });
} catch (error) {
  showError(error?.message || 'PayPal checkout did not load.');
}
