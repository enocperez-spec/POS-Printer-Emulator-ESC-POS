# POS Printer Emulator purchase site

PHP customer purchase site for `https://buy.posprinteremulator.com`. All owner controls live in the single portal at `https://admin.posprinteremulator.com`.

## Purchase flow

1. The browser collects the customer/company name and email address.
2. The server creates the PayPal order using the configured price; the browser never controls the amount.
3. After PayPal approval, the server captures and verifies the payment status, amount, and currency.
4. The order enters `PAID_AWAITING_APPROVAL`.
5. The owner signs in at `admin.posprinteremulator.com`, approves the order, and the Buy server generates a key compatible with the desktop application.
6. The key is emailed to the purchase address. Failed delivery is retained for a safe retry without generating a second key.

## Single Admin portal

The Buy site has no owner-facing Admin area. Its protected Admin APIs are called server-to-server by `admin.posprinteremulator.com`, where **Purchase Orders** and **Purchase Pricing** appear beside the existing dashboard and License Manager.

## Server requirements

- PHP 8.2 or later
- cURL, OpenSSL, PDO SQLite
- HTTPS
- PHP `mail()` or a host mail relay

The target host was probed on July 14, 2026 and reported PHP 8.4 with all required extensions.

## Protected configuration

Copy `private/config.example.php` to `private/config.php` and fill in the fallback price, PayPal settings, Admin API token, and email addresses. Place the existing matching `vendor-private-key.pem` in `private/`.

Both protected files are ignored by Git and denied by `.htaccess`. Never commit or email the private signing key or PayPal secret.

Before enabling checkout, confirm the PayPal environment and run one controlled payment from order creation through email delivery and desktop activation.
