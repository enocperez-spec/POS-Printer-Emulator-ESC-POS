# Production integration-token rotation

This runbook rotates the two server-to-server credentials used by the purchase site and Admin Portal. Never commit the replacement values or paste them into GitHub, tickets, or application logs.

## 1. Generate two independent values

Use a password manager or a trusted local generator. Each value must contain at least 32 random characters:

```text
PPE_ADMIN_API_TOKEN=<new random value>
PPE_MAINTENANCE_API_TOKEN=<different new random value>
```

Do not reuse the PayPal secret, an administrator password, or a token from another environment.

## 2. Configure the production host

Preferred: configure these as hosting-environment variables for the PHP process:

```text
PPE_ADMIN_API_TOKEN
PPE_MAINTENANCE_API_TOKEN
PPE_PAYPAL_SECRET
```

If the host does not provide environment variables, keep the values in a server-only configuration file outside the public web root and load them before the public `buy-website` files. The existing `buy-website/private/.htaccess` remains a second layer of protection; it is not a substitute for keeping secrets outside the document root.

Ensure the web process can read the file but ordinary web requests cannot download it. Do not place secrets in JavaScript, HTML, a public `.env` file, or an uploaded backup.

## 3. Deploy the v0.3.30 code

Deploy the updated `buy-website/includes/bootstrap.php` and `buy-website/README.md`, then restart PHP-FPM/Apache or clear the PHP opcode cache if the host requires it.

## 4. Verify before revoking the old values

Using a private administrator workstation, verify the normal workflows with the new values:

- Admin Portal price and license-management requests succeed.
- Purchase-site maintenance and license fulfillment requests succeed.
- PayPal capture and approval still complete.
- The desktop activation and maintenance refresh endpoints still succeed.

Then send one request using each old value. Both must return HTTP `401 Unauthorized`. Do not include either token in the request body or a log file.

## 5. Remove and audit

- Remove old values from `private/config.php`, server backups, deployment archives, shell history, and logs.
- Search the repository and deployed document root for the old values.
- Confirm the new values are not present in Git, browser source, generated HTML, or client-side JavaScript.
- Record only the rotation date and credential identifiers, never the credential values.

## Rollback

If a business-critical workflow fails, restore the previous value only through the hosting secret store, investigate the failure, and rotate again after correcting the configuration. Never re-commit an old token.
