# POS Printer Emulator Customer Portal

The Customer Portal is a PHP 8 and MariaDB/MySQL application intended for the dedicated `userportal.posprinteremulator.com` document root. v0.3.43 establishes verified customer accounts; v0.3.44 adds protected self-service renewal and upward license-upgrade checkout.

## Protected setup

1. Create a least-privilege database account that can read customer, license, installation, purchase, support, consent, and event records and write only portal-owned records plus documented customer updates.
2. Copy `private/config.example.php` to the server-owned `private/config.php`.
3. Set the canonical HTTPS base URL and a random independent 32-byte AES key encoded as Base64.
4. Configure the server-to-server Admin Portal support endpoint and its random bearer token. Store only the token digest in the Admin Portal configuration.
5. Use `mail_transport=php_mail` only after the hosting sender is verified. `outbox` preserves mail safely for a configured delivery worker.
6. Apply `database/migrate-customer-portal-v0.3.43.sql`.
7. For v0.3.44, configure the canonical HTTPS Buy URL and apply `database/migrate-self-service-commerce-v0.3.44.sql`.

The C# publisher can create the protected server configuration without writing secrets into the repository:

```powershell
dotnet run --project tools/POSPrinterEmulator.WebsitePublisher -- configure-customer-portal /userportal_posprinteremulator
dotnet run --project tools/POSPrinterEmulator.WebsitePublisher -- migrate-customer-portal https://admin.posprinteremulator.com/api/v1/migrate-customer-portal.php
dotnet run --project tools/POSPrinterEmulator.WebsitePublisher -- migrate-self-service-commerce https://admin.posprinteremulator.com/api/v1/migrate-self-service-commerce.php
```

The first command reuses the protected v0.3.42 service token and creates a portal-only AES key. The AES key is retained in a Windows DPAPI-protected recovery file so later deployments do not make existing MFA secrets unreadable.

Never place production credentials, bearer tokens, encryption keys, email-provider credentials, or database passwords in this directory before committing or packaging it.

The browser receives only a 20-minute opaque checkout token. The Buy service resolves customer and license ownership through the protected Admin service, calculates the current server price, verifies PayPal capture, and then requests idempotent fulfillment. Do not add PayPal credentials, activation-key signing material, or browser-supplied amounts to the Customer Portal.

## Local checks

```powershell
php tests/php/customer-portal-tests.php
php -l customer-portal/index.php
php -l customer-portal/portal.php
php -l customer-portal/verify.php
```
