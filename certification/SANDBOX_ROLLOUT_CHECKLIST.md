# Sandbox-first release rollout checklist

Use this checklist for every POS Printer Emulator application, website, portal,
database, installer, licensing, payment, or email-template release. A production
deployment is blocked until every applicable critical item passes in the sandbox.
Record machine-enforced gate results using
`certification/ROLLOUT_AUTOMATION.md`.

## Release record

- Release version:
- Release candidate commit:
- Release owner:
- Test owner:
- Rollout ticket or issue:
- Sandbox certification run ID:
- Journey correlation ID:
- Installer filename:
- Installer SHA-256:
- Sandbox test started UTC:
- Sandbox test completed UTC:
- Planned production window:
- Rollback owner:

## Release decision rules

- [ ] **CRITICAL:** The exact commit and installer tested in the sandbox are the
  artifacts proposed for production.
- [ ] **CRITICAL:** No production database, payment, email, storage, or API credential
  is available to the sandbox.
- [ ] **CRITICAL:** Every applicable critical check below passes.
- [ ] All failures are documented with expected result, actual result, evidence, and
  a linked defect.
- [ ] A failed critical check blocks the release. It cannot be waived without a
  written release-owner decision and compensating controls.
- [ ] Any code or configuration change after approval invalidates the approval and
  requires the affected checks to be repeated.

## 1. Scope and change review

- [ ] Record all application, website, portal, API, database, installer, email,
  licensing, payment, documentation, and infrastructure changes.
- [ ] Identify database migrations and whether they are backward compatible.
- [ ] Identify new environment variables, credentials, scheduled jobs, and webhooks.
- [ ] Identify customer-visible wording, pricing, license, EULA, privacy, or support
  policy changes.
- [ ] Identify destructive operations and prepare a tested rollback or recovery path.
- [ ] Confirm the version is synchronized in application metadata, installer,
  website, download page, pricing, FAQ, documentation, Admin Portal, release notes,
  and GitHub release metadata.
- [ ] Freeze the release-candidate commit before certification begins.

## 2. Sandbox isolation and preflight

- [ ] **CRITICAL:** Confirm these hosts resolve to their isolated directories:
  - `sandbox.posprinteremulator.com`
  - `userportal-sandbox.posprinteremulator.com`
  - `admin-sandbox.posprinteremulator.com`
  - `buy-sandbox.posprinteremulator.com`
  - `support-sandbox.posprinteremulator.com`
- [ ] **CRITICAL:** Confirm every sandbox host uses HTTPS and does not redirect to a
  production host.
- [ ] **CRITICAL:** Confirm the sandbox database account cannot access production
  databases.
- [ ] Confirm PayPal uses `https://api-m.sandbox.paypal.com`.
- [ ] Confirm email delivery is disabled or restricted to an approved test allowlist.
- [ ] Confirm sandbox data uses test identities and non-production email addresses.
- [ ] Confirm deployment recovery files are encrypted outside the repository.
- [ ] Confirm no credential is present in source, logs, reports, screenshots, or
  deployment artifacts.
- [ ] Run the staging preflight and attach its report:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- preflight --config C:\secure\ppe-staging.json
```

- [ ] Preflight result: Passed / Failed
- Evidence:

## 3. Source and build quality gate

- [ ] Restore all dependencies from approved package sources.
- [ ] Build the viewer, service, desktop application, updater, database tool, and
  installer in Release configuration.
- [ ] Run all C# unit and component tests.
- [ ] Run all PHP contract suites.
- [ ] Validate SQL parsing for the full schema and every new migration.
- [ ] Validate synchronized release metadata and license catalogs.
- [ ] Run SEO validation and the customer-facing activation-key absence scan.
- [ ] Run the local gate against the release-candidate installer:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- local-gate --installer artifacts/installer/POSPrinterEmulatorSetup-VERSION-win-x64.exe
```

- [ ] **CRITICAL:** Local gate result: Passed / Failed
- [ ] Confirm the reported installer SHA-256 matches the published checksum.
- Evidence:

## 4. Sandbox deployment

- [ ] Back up the current sandbox configuration and database if test history must be
  retained.
- [ ] Deploy the public website, Customer Portal, Admin Portal, Buy site, and Support
  site only to their sandbox directories.
- [ ] Apply the protected sandbox configuration without displaying credentials.
- [ ] Apply all schema and migration changes.
- [ ] Seed Trial, Lite, Pro, Enterprise, and expired-maintenance scenarios.
- [ ] Upload the exact release-candidate installer and checksum.
- [ ] Verify the installer checksum on the hosting server.
- [ ] Confirm no temporary marker, migration helper, debug file, or credential export
  is publicly accessible.

## 5. Website and endpoint smoke tests

- [ ] **CRITICAL:** All five sandbox home pages return their expected success or login
  response.
- [ ] Confirm the public site, pricing, download, documentation, FAQ, EULA, privacy,
  environmental methodology, and User Portal guide pages load.
- [ ] Confirm navigation remains on sandbox hosts.
- [ ] Confirm purchase buttons preserve the selected license and lead to the sandbox
  Customer Portal.
- [ ] Confirm the vCurrent download URL returns the correct installer size.
- [ ] Confirm CSS, JavaScript, logos, dark-mode application screenshots, and favicons
  load without 404 responses.
- [ ] Confirm account-link, telemetry, licensing, portal-commerce, and support APIs
  return the expected status for supported and unsupported methods.
- [ ] Confirm protected setup and private configuration files are not readable by GET.
- [ ] Check browser console and server logs for new errors.

## 6. Customer account and security journeys

- [ ] Create a new Customer Portal account.
- [ ] Verify the email address and create the password.
- [ ] Sign in and sign out successfully.
- [ ] Request a password reset and complete it using the single-use link.
- [ ] Confirm expired and reused verification/reset links are rejected clearly.
- [ ] Configure two-factor authentication using the QR code.
- [ ] Verify authenticator-code and recovery-code sign-in.
- [ ] Disable two-factor authentication with password, code, warning, audit, session
  revocation, and customer notification.
- [ ] Perform an authorized Admin MFA reset with identity verification and a reason.
- [ ] Confirm the customer must enroll again at the next sign-in.
- [ ] Confirm ten minutes of inactivity signs the customer out and returns them to the
  login page.
- [ ] Confirm destructive account actions require recent password confirmation and do
  not overlap or break at common screen sizes.

## 7. Installer and desktop application

- [ ] **CRITICAL:** Test a clean installation on a supported Windows 11 Pro machine.
- [ ] Confirm the EULA is displayed and must be accepted.
- [ ] Confirm the installer includes every required runtime and dependency.
- [ ] Confirm the desktop application and background service start correctly.
- [ ] Confirm uninstall removes application-owned components cleanly.
- [ ] Test reinstall after uninstall.
- [ ] Test upgrade from the oldest supported version to the release candidate.
- [ ] Restart the application and confirm settings and license state persist.
- [ ] Restart Windows and confirm the service and application recover correctly.
- [ ] Confirm light and dark modes render correctly, with dark-mode screenshots used
  in customer documentation.
- [ ] Confirm listener creation, TCP/IP Port 9100 receipt capture, receipt preview,
  Commands, Raw data, Job details, collapse controls, job clearing, capture/replay,
  print/PDF, stored logos, printer state, backup, and restore.
- [ ] Confirm actionable errors appear for port conflicts, unavailable services,
  malformed ESC/POS input, and unsupported commands.

## 8. Trial and license entitlements

- [ ] Trial starts automatically for a new verified account/computer.
- [ ] Trial permits five external emulated print jobs per local day.
- [ ] Built-in test receipts do not consume the daily Trial allowance.
- [ ] Trial history, watermark, listener allowance, and premium-feature restrictions
  match the published comparison.
- [ ] Lite applies one printer listener and its documented features.
- [ ] Pro applies up to two printer listeners and its documented features.
- [ ] Enterprise applies up to fifteen printer listeners and Enterprise-only features.
- [ ] No customer or administrator journey requires an activation key.
- [ ] License type, active status, linked computer, and Maintenance and Support date
  agree in the application, Customer Portal, and Admin Portal.
- [ ] License changes synchronize promptly without reinstalling.

## 9. Computer linking, transfer, and recovery

- [ ] Start **Link This Computer** from the application.
- [ ] Confirm the code is single-use, short-lived, and displayed with the correct
  computer/application details.
- [ ] Approve the computer from the verified Customer Portal account.
- [ ] Select an eligible entitlement and confirm automatic application licensing.
- [ ] Reject an expired, reused, altered, or cross-account link request.
- [ ] Enforce the license device allowance.
- [ ] Deactivate the original computer and activate a second linked computer.
- [ ] Confirm history records both the deactivation and new activation.
- [ ] Test support-assisted recovery when the original computer is unavailable.
- [ ] Rename the computer and test a different Windows user without creating an
  unintended duplicate entitlement.

## 10. Purchases, upgrades, and PayPal sandbox

- [ ] **CRITICAL:** Complete new Lite, Pro, and Enterprise PayPal sandbox purchases.
- [ ] Confirm the selected license survives registration and sign-in redirects.
- [ ] Confirm the payment amount, currency, license, customer, correlation ID, and
  PayPal reference agree across Buy, Customer, and Admin records.
- [ ] Confirm duplicate webhook/capture delivery does not create a duplicate order or
  entitlement.
- [ ] Test customer-canceled checkout.
- [ ] Test declined or failed payment and a successful retry.
- [ ] Test delayed webhook delivery and reconciliation.
- [ ] Test Lite-to-Pro, Lite-to-Enterprise, and Pro-to-Enterprise upgrades.
- [ ] Confirm existing owners see upgrade or explicitly labeled additional-license
  choices rather than accidental duplicates.
- [ ] Test refund and chargeback-review handling.
- [ ] Confirm every purchase and entitlement change is audited.
- [ ] Confirm every fulfilled purchase produces one stable invoice number and a
  downloadable, logo-branded invoice plus receipt in Customer Portal Billing.
- [ ] Confirm the customer-facing receipt displays the stable invoice number and the
  verified PayPal capture ID as the PayPal approval reference.
- [ ] Confirm Customer Portal Billing provides one clear **View Receipt** action;
  emailed purchase confirmations retain the logo-branded PDF invoice attachment.

## 11. Maintenance and Support

- [ ] New paid licenses receive the correct initial coverage period.
- [ ] Customer Portal displays the exact **Maintenance and Support Until** date.
- [ ] Three-month reminders show the date, days remaining, and renewal action.
- [ ] Expired coverage leaves purchased permanent features working.
- [ ] Expired coverage blocks ineligible update downloads and protected recovery
  actions in both the UI and backend.
- [ ] Complete each renewal tier through PayPal sandbox.
- [ ] Confirm renewal extends coverage by the correct period and restores eligible
  updates and support access.
- [ ] Confirm application and portals synchronize the renewed date.

## 12. Email and communication templates

- [ ] Use only an approved allowlisted test recipient.
- [ ] Verify email verification, password recovery, purchase confirmation, welcome,
  setup, upgrade, maintenance, release, promotion, inactive-user, support, and MFA
  security templates as applicable.
- [ ] Confirm Service templates use `info@buy.posprinteremulator.com`.
- [ ] Confirm Purchase, Marketing, and sales templates use
  `sales@buy.posprinteremulator.com`.
- [ ] Confirm branding, logo, sender, recipient sample, subject, preview text, body,
  footer, buttons, links, and placeholders.
- [ ] Confirm the purchase-confirmation email visibly displays the product logo,
  includes the matching logo-branded PDF invoice, and Brevo reports one attachment
  without exposing payment credentials.
- [ ] Confirm desktop and mobile previews succeed before activation.
- [ ] Confirm missing/invalid placeholders and broken links block approval.
- [ ] Confirm templates do not encourage replies and include the unmonitored-inbox
  notice when applicable.
- [ ] Confirm documentation, Help Center, and support-request URLs use global settings.
- [ ] Confirm frequency caps, consent, inactivity stop conditions, enable/disable
  status, mapping, tags, and trigger-flow descriptions.
- [ ] Confirm provider message IDs and delivery events retain the journey correlation
  ID.
- [ ] **CRITICAL:** Confirm the protected communications cron runs automatically on
  its configured schedule and a newly queued essential purchase message is delivered
  without any manual worker action.

## 13. Admin Portal operations and audit

- [ ] Dashboard counts agree with seeded/custom journey data.
- [ ] Customer, installation, purchase, license, maintenance, communication, support,
  geographic, and usage views load without errors.
- [ ] Purchase pricing can be read and updated through the sandbox Buy integration.
- [ ] Authorized roles can change, assign, revoke, deactivate, and recover entitlements.
- [ ] Destructive actions require confirmation and a reason.
- [ ] MFA reset is restricted to authorized roles and never exposes the authenticator
  secret.
- [ ] Audit records include administrator, action, customer, device, UTC time, IP
  digest/address as designed, reason, outcome, and correlation ID.
- [ ] Disabled or unmapped email templates cannot be activated.
- [ ] No full activation key, password, token, payment secret, or authenticator secret
  is displayed or logged.

## 14. Compatibility, usability, accessibility, and SEO

- [ ] Test supported ESC/POS text, alignment, sizing, raster images, feeds, cuts,
  barcodes/QR codes, and documented Epson-compatible commands.
- [ ] Validate common POS developers’, menu administrators’, and technicians’
  workflows.
- [ ] Check desktop, tablet, and mobile website layouts.
- [ ] Confirm no horizontal scrolling, text/background contrast defect, clipped
  content, overlapping controls, keyboard trap, or unreadable focus state.
- [ ] Confirm accessible labels, keyboard navigation, and meaningful image alt text.
- [ ] Confirm title, H1, description, canonical URL, structured data, breadcrumb,
  sitemap, robots directives, and internal links.
- [ ] Confirm sandbox pages cannot accidentally become production canonical URLs or
  submit production IndexNow/Search Console updates.
- [ ] Check for broken links, missing assets, mixed content, and browser console errors.

## 15. Failure, recovery, and rollback

- [ ] Stop each dependent sandbox service in turn and confirm a clear recovery message.
- [ ] Test licensing-server unavailability, offline grace, grace expiration, recovery,
  and manual refresh.
- [ ] Test database/API timeout behavior without exposing internal diagnostics.
- [ ] Confirm payment and entitlement reconciliation can recover from a partial
  transaction.
- [ ] Confirm email/provider failure queues or records the failure without losing the
  customer action.
- [ ] Restore the prior sandbox application/configuration version.
- [ ] Restore or roll forward the sandbox database using the documented procedure.
- [ ] Confirm rollback does not reuse new-only schema in an incompatible application.
- [ ] Record measured rollback duration and the person authorized to trigger it.

## 16. Evidence and sandbox sign-off

- [ ] Attach redacted certification HTML/JSON reports.
- [ ] Attach screenshots or video for each critical customer journey.
- [ ] Record PayPal sandbox references, email provider message IDs, correlation IDs,
  relevant logs, database assertions, and Windows restart results.
- [ ] Record all defects and retest evidence.
- [ ] Independent first-time customer test completed using
  `certification/E2E_CUSTOMER_EXPERIENCE_CHECKLIST.md`.
- [ ] The `manual-customer` rollout gate references a passing local `e2e-verify`
  `report.json` bound to the same version, commit, and installer.
- [ ] Release notes and customer documentation are complete.
- [ ] **CRITICAL:** Release owner approves the exact commit and artifact.
- [ ] **CRITICAL:** Test owner confirms all critical sandbox journeys passed.
- [ ] **CRITICAL:** Rollback owner confirms the rollback package and instructions are
  ready.

Sandbox decision:

- [ ] PASS — eligible for production rollout.
- [ ] FAIL — production rollout blocked.

Release owner/signature:

Test owner/signature:

Rollback owner/signature:

Approval UTC:

## 17. Production rollout

Perform this section only after sandbox PASS.

- [ ] Reconfirm the production destination directories before writing.
- [ ] Back up production application/configuration files and database.
- [ ] Confirm maintenance mode or customer communication if required.
- [ ] Deploy the exact sandbox-approved commit and artifact.
- [ ] Apply only approved production configuration and migrations.
- [ ] Never copy sandbox customers, purchases, email addresses, PayPal references, or
  certification seed data into production.
- [ ] Verify the production installer checksum.
- [ ] Run read-only production smoke tests for all five public surfaces.
- [ ] Complete one controlled, non-destructive account/login/license synchronization
  check.
- [ ] Confirm production PayPal, email, webhook, scheduled job, and API configurations
  are active and point only to production services.
- [ ] Complete one controlled production purchase and verify visible email and PDF
  logo branding, Billing invoice/receipt links, invoice number, amount, sender, and
  delivery event before opening checkout broadly.
- [ ] Check production logs, error rate, response time, payment state, email queue, and
  licensing synchronization.
- [ ] Announce completion or trigger rollback according to the decision criteria.

## 18. Post-release monitoring and closure

- [ ] Monitor critical errors, failed payments, license synchronization, account
  linking, email delivery, support submissions, and installer downloads.
- [ ] Review at 15 minutes, 1 hour, 4 hours, and 24 hours.
- [ ] Confirm no unexpected increase in authentication, payment, licensing, or API
  failures.
- [ ] Confirm Search Console/sitemap changes only when the release includes public SEO
  changes.
- [ ] Record incidents, customer reports, rollback decisions, and follow-up work.
- [ ] Attach final production evidence and close the rollout ticket.

Production result:

- [ ] Successful
- [ ] Rolled back
- [ ] Partially deployed with documented follow-up

Closed by:

Closed UTC:
