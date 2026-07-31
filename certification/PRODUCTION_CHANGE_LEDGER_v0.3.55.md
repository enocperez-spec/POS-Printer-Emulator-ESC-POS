# v0.3.55 sandbox-to-production change ledger

This ledger records changes discovered or validated during the v0.3.55 sandbox
customer journey. It supplements `SANDBOX_ROLLOUT_CHECKLIST.md`; it does not authorize
a production deployment. Production publishing remains blocked until the rollout
record, evidence, approvals, exact commit, and installer all pass.

## Classification rules

- **Promote source:** deploy the tested source file to the equivalent production
  directory after the production gate passes.
- **Recreate securely:** configure the production equivalent using production
  credentials or IONOS settings; never copy the sandbox secret or value.
- **Sandbox only:** retain only in sandbox or certification infrastructure.
- **Removed:** temporary diagnostics must be absent from both environments.

## Source changes that must be promoted

| Status | Change | Tested sandbox destination | Production destination | Production verification |
| --- | --- | --- | --- | --- |
| [ ] | Allow the configured Buy origin in the Customer Portal CSP `form-action`, fixing cross-origin Lite, Pro, and Enterprise checkout redirects. | `/userportal_sandbox_posprinteremulator/includes/bootstrap.php` | `/userportal_posprinteremulator/includes/bootstrap.php` | Portal response names only `self` and `https://buy.posprinteremulator.com`; all purchase and upgrade buttons reach Buy. |
| [ ] | Display a stable invoice number and the verified PayPal capture ID as the **PayPal approval reference** on receipts. | `/userportal_sandbox_posprinteremulator/includes/portal-data.php`, `/receipt.php` | Equivalent files below `/userportal_posprinteremulator` | A completed production test transaction shows one stable invoice number, capture reference, correct amount, and no payment credentials. |
| [ ] | Distinguish PayPal capture and order references on the portal invoice compatibility page. | `/userportal_sandbox_posprinteremulator/invoice.php` | `/userportal_posprinteremulator/invoice.php` | Capture and order references match the provider record. |
| [ ] | Keep only **View Receipt** in Customer Portal Billing while retaining internal invoice generation for email attachments and historical links. | `/userportal_sandbox_posprinteremulator/portal.php` | `/userportal_posprinteremulator/portal.php` | Billing shows one document action and the receipt contains the invoice fields. |
| [ ] | Label the verified capture ID as **PayPal approval reference** in the branded PDF invoice. | `/admin_sandbox_posprinteremulator/includes/communications.php` | `/admin_posprinteremulator/includes/communications.php` | Purchase-confirmation email contains exactly one logo-branded PDF with matching invoice and capture references. |

## Production configuration and infrastructure

| Status | Classification | Requirement | Production verification |
| --- | --- | --- | --- |
| [~] | Recreate securely | Production IONOS cron was configured with `/usr/bin/php8.4 -f /homepages/12/d4299934508/htdocs/admin_posprinteremulator/private/communications-cron.php`. Runtime verification is still required. | A newly fulfilled purchase moves from Pending to Sent automatically, without a manual worker run, within the expected schedule. |
| [ ] | Recreate securely | Confirm production communications configuration uses production Brevo mode, approved sender identities, production webhook URL, and production allowlist/policy. | Purchase, license-ready, security, and support messages record provider message IDs and delivery events. |
| [ ] | Recreate securely | Confirm production PayPal REST configuration uses the live API host and live application credentials. | Preflight rejects the sandbox PayPal host and a controlled live-mode validation succeeds without displaying secrets. |
| [ ] | Recreate securely | Confirm all production portal, Buy, Admin, Support, documentation, and account-link URLs use production hosts. | No production page or desktop response links to a `*-sandbox` hostname. |
| [ ] | Recreate securely | Apply only required additive database migrations to the production database. | Migration is idempotent, backup exists, and schema validation passes. No sandbox customer, purchase, or PayPal record is copied. |

## Sandbox-only configuration that must not move

- Sandbox MariaDB host, database account, data, and duplicate certification customers.
- PayPal Sandbox API host, buyer account, application credentials, orders, captures,
  and transaction references.
- Brevo test allowlist and sandbox sender/display-name configuration.
- Certification desktop endpoint override and sandbox host allowlist.
- Sandbox domain-to-directory assignments and sandbox private configuration files.
- Sandbox checkout rate-limit resets, deterministic seeds, test invoices, and
  certification email messages.
- Temporary diagnostic endpoints or downloaded protected configuration files.

## Temporary diagnostics removal

- [x] License diagnostic endpoint removed from the sandbox Admin directory.
- [x] Communications diagnostic/worker endpoint removed from the sandbox Admin
  directory.
- [x] Downloaded protected sandbox configuration and secret files removed from the
  local temporary directory.
- [ ] Before production approval, scan all five sandbox and production roots for
  diagnostic, debug, temporary migration, secret export, and test-only files.

## Sandbox evidence recorded

- Lite purchase completed and fulfilled.
- Lite entitlement linked to `EPEREZP11`, persisted across application and Windows
  restart, and synchronized with active Maintenance and Support.
- Lite-to-Pro upgrade completed and fulfilled without creating a duplicate license.
- Pro entitlement revision synchronized to the linked computer with no sync error.
- Customer Portal and hosted database showed the same Pro tier, computer, license,
  and Maintenance and Support date.
- Purchase-confirmation email delivered with a branded PDF invoice after the worker
  processed the queue.
- The missing upgrade invoice was traced to an unprocessed communications queue, not
  missing invoice-generation code.
- A controlled sandbox password-recovery request on July 28, 2026 delivered exactly
  once to the approved certification inbox. The message used the sandbox service
  sender, included the branded HTML design, recorded provider identifiers, and passed
  DKIM, SPF, and DMARC. This is delivery and idempotency evidence only: password
  recovery immediately invokes the protected worker and therefore does not prove the
  scheduled cron processed a Pending message.
- A queue-only sandbox purchase-confirmation test was created at
  `2026-07-28 05:18:01 UTC`. It remained `Pending` with zero attempts after the full
  five-minute delivery window and two subsequent `*/5` boundaries, including after
  the sandbox job was reported configured. After the IONOS edit form was explicitly
  saved, the message also remained untouched after the `2026-07-28 05:50 UTC`
  boundary. No manual worker was invoked. The sandbox cron gate therefore failed and
  remains blocked until the IONOS execution error or schedule is corrected and a
  fresh queue-only record is processed automatically.
- Receipt/invoice and communications contract suites passed after adding the invoice
  number and PayPal capture-reference labels.

## Open production blockers

- [ ] Configure and prove automatic five-minute communications processing in the
  sandbox; manual processing is not acceptable release evidence.
- [ ] Verify the newly configured production cron executes successfully without
  sending a production test message before rollout approval; retain production
  delivery pause/policy controls until the production release is authorized.
- [ ] Update the active E2E record with completed Lite purchase, Pro upgrade, account
  linking, restart persistence, invoice delivery, and current defects.
- [ ] Complete the remaining Trial, Pro, Enterprise, maintenance, transfer/recovery,
  PayPal failure/refund/idempotency, support, security, accessibility, uninstall, and
  reinstall journeys.
- [ ] Freeze a clean release-candidate commit and rebuild the installer.
- [ ] Run the complete source gate and first-time customer gateway against that exact
  commit and installer.
- [ ] Record all rollout gates and obtain release-owner, test-owner, and rollback-owner
  approvals.
