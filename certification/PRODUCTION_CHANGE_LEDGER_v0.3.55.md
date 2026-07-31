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
| [ ] | Clear stale computer synchronization errors immediately after the Customer Portal approves an eligible account license. | `/userportal_sandbox_posprinteremulator/portal.php` | `/userportal_posprinteremulator/portal.php` | A newly approved computer displays **Active** without retaining an earlier Unlinked error. |
| [ ] | Synchronize authoritative Active, Expired, and Revoked Maintenance and Support states to linked desktops while preserving the permanent paid license. | `/admin_sandbox_posprinteremulator/api/v1/device-entitlement.php` plus the v0.3.55 desktop | `/admin_posprinteremulator/api/v1/device-entitlement.php` plus the signed production desktop installer | Admin revocation disables updates/support after refresh but Pro features remain; restoration returns maintenance to Active. |

## Production configuration and infrastructure

| Status | Classification | Requirement | Production verification |
| --- | --- | --- | --- |
| [ ] | Recreate securely | Replace the production direct-PHP job with `/bin/sh /homepages/12/d4299934508/htdocs/admin_posprinteremulator/private/communications-cron.sh` after the passing rollout gate. Keep delivery policy controls in their approved production state. | A newly fulfilled purchase moves from Pending to Sent automatically, without a manual worker run, within the expected schedule. Private launch/status evidence reports exit code 0 and no error class. |
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
- A second queue-only sandbox service test was created at
  `2026-07-31 18:08:44 UTC`. It remained `Pending` with zero attempts through
  multiple `*/5` IONOS boundaries. A verified SSH diagnostic then ran the
  communications worker successfully; the record moved to `Deferred` with
  `TEMPLATE_PREVIEW_REQUIRED`, proving the deployed PHP dependencies and worker can
  execute. The attempt count remained `1` after the next configured IONOS boundary.
- A temporary one-minute UnixCron proof used `/usr/bin/touch` to create
  `private/cron-scheduler-proof.txt`, proving that the IONOS scheduler itself runs
  Unix commands for this webspace. The temporary proof job, an additional four-minute
  PHP test job, and the proof file were removed after the result was recorded. The
  remaining fault is therefore isolated to the scheduled PHP communications command
  or its Cron-only runtime context. Automatic communications processing is not yet
  certified.
- A private shell launcher isolated the final Cron-only difference: IONOS launched
  PHP with a non-CLI SAPI, so the original worker returned through its direct-web
  guard. The worker now permits only CLI execution or the private launcher marker
  `PPE_COMMUNICATIONS_CRON=1`; normal web requests remain denied.
- A fresh non-manual `email_verification` record
  (`76a3802d-64b3-4a61-bc53-ee186d9958a0`) was queued before the corrected job ran.
  At `2026-07-31T19:30:15Z`, IONOS launched the worker automatically. The private
  status record reported `sent=1`, `idle=1`, completion, PHP exit code `0`, and no
  error class. A privacy-safe database check showed state `Sent`, one attempt, and a
  provider message identifier. No manual worker or Admin retry was invoked after
  enqueue.
- Receipt/invoice and communications contract suites passed after adding the invoice
  number and PayPal capture-reference labels.
- A fresh Pro computer link and relink reconciled the same active device, entitlement,
  and maintenance date across the desktop, Customer Portal, and Admin Portal.
- Live maintenance revocation correctly remained server-side but exposed that the
  device-entitlement response omitted the revocation status. The API and desktop
  contract were corrected, regression tested, and deployed only to sandbox. The
  certification entitlement was restored after the test.

## Open production blockers

- [x] Capture the scheduled PHP worker error in the sandbox Cron-only runtime,
  correct it, validate the required template preview, and prove a fresh queue-only
  message advances automatically on the schedule without manual processing.
- [ ] Verify the newly configured production cron executes successfully without
  sending a production test message before rollout approval; retain production
  delivery pause/policy controls until the production release is authorized.
- [x] Update the active E2E record with completed Lite purchase, Pro upgrade, account
  linking, restart persistence, invoice delivery, and current defects.
- [ ] Complete the remaining Trial, Pro, Enterprise, maintenance, transfer/recovery,
  PayPal failure/refund/idempotency, support, security, accessibility, uninstall, and
  reinstall journeys.
- [x] Freeze a clean release-candidate commit and rebuild the installer.
- [x] Run the complete source gate against that exact commit and installer.
- [ ] Run the independent first-time customer gateway against that exact commit and
  installer.
- [ ] Record all rollout gates and obtain release-owner, test-owner, and rollback-owner
  approvals.
