# POS Printer Emulator purchase site

PHP customer purchase site for `https://buy.posprinteremulator.com`. All owner controls live in the single portal at `https://admin.posprinteremulator.com`.

## Purchase flow

1. The browser collects the customer/company name and email address.
2. The customer chooses Lite, Pro, or Enterprise. The server creates the PayPal order using that tier's configured price; the browser never controls the amount.
3. After PayPal approval, the server captures and verifies the payment status, amount, and currency.
4. The order enters `PAID_AWAITING_APPROVAL`.
5. The owner signs in at `admin.posprinteremulator.com`, approves the order, and the Buy server generates a key compatible with the desktop application.
6. The key is emailed to the purchase address. Failed delivery is retained for a safe retry without generating a second key.

Every new paid license is permanent and includes one year of Application Maintenance and Support. The activation key carries the initial maintenance expiration in the signed v3 payload.

## Optional maintenance renewal

`?product=maintenance&tier=Lite`, `Pro`, or `Enterprise` opens the one-time annual renewal flow. The customer enters the License ID and matching registration details. The Buy server verifies the license with the Admin maintenance service before creating a PayPal order; the browser cannot choose a different tier or amount.

After a completed capture is approved, the Admin service extends coverage idempotently and returns a signed `PPEM1-` entitlement. An early renewal adds one calendar year to the existing expiration. A lapsed renewal begins at the captured-payment time. Renewals are never recurring PayPal agreements and never change the permanent license.

Default annual renewal prices are Lite `$9.99`, Pro `$19.99`, and Enterprise `$59.99`. All are server-controlled and editable in the Admin Portal.

## Desktop entitlement refresh contract

The desktop sends `POST https://admin.posprinteremulator.com/api/maintenance-entitlement.php` with JSON `licenseId` and `registrationDigest`. The digest is lowercase hexadecimal SHA-256 of UTF-8 `NORMALIZED CUSTOMER\nnormalized-email`. Only ASCII space, tab, CR, LF, vertical tab, and form feed are trimmed/collapsed; customer ASCII `a-z` is mapped to `A-Z`, email ASCII `A-Z` is mapped to `a-z`, and all non-ASCII bytes are preserved. The endpoint never accepts or returns an activation key.

The response includes `status` (`active`, `expired`, `revoked`, or `not_found`), UTC `serverTime`, License ID, tier, maintenance expiration, renewal URL, and a signed `PPEM1-` token only while entitled. Requests are rate-limited. The desktop verifies the signed token before saving it.

The Buy-to-Admin maintenance service uses its own random `maintenance.api_token`. It must not reuse the Admin-to-Buy `admin_api_token`; compromising either integration therefore does not grant the opposite direction of access.

## Single Admin portal

The Buy site has no owner-facing Admin area. Its protected Admin APIs are called server-to-server by `admin.posprinteremulator.com`, where **Purchase Orders** and **Purchase Pricing** appear beside the existing dashboard and License Manager.

## Server requirements

- PHP 8.2 or later
- cURL, OpenSSL, PDO SQLite
- HTTPS
- PHP `mail()` or a host mail relay

The target host was probed on July 14, 2026 and reported PHP 8.4 with all required extensions.

## Protected configuration

Copy `private/config.example.php` to `private/config.php` and fill in the fallback prices, PayPal settings, Admin API token, and email addresses. Lite defaults to `$24.99`; all three paid prices can be changed from Purchase Pricing in the Admin Portal. Place the existing matching `vendor-private-key.pem` in `private/`.

For production, provide `PPE_ADMIN_API_TOKEN`, `PPE_MAINTENANCE_API_TOKEN`, and `PPE_PAYPAL_SECRET` as hosting-environment secrets. These values override the file settings, must be at least 32 characters for integration tokens, and must be different from one another. Rotate both integration tokens after any suspected exposure and verify the old values receive HTTP 401.

Public and in-app upgrade links may preselect a paid level with `?tier=Lite`, `?tier=Pro`, or `?tier=Enterprise`. Invalid or unavailable values are ignored safely.

Both protected files are ignored by Git and denied by `.htaccess`. Never commit or email the private signing key or PayPal secret.

Before enabling checkout, confirm the PayPal environment and run one controlled payment from order creation through email delivery and desktop activation.
