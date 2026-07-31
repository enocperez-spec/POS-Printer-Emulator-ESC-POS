# End-to-end customer experience gateway

This gateway validates the complete customer journey in the isolated sandbox. It is
not a developer smoke test. Use at least one tester who did not implement the release,
provide only published customer instructions, and record evidence for every journey.

A failed or incomplete critical journey blocks production.

## Test record

- Product version:
- Git commit:
- Installer:
- Installer SHA-256:
- E2E run ID:
- Journey correlation ID:
- First-time tester:
- Test coordinator:
- Windows 11 Pro version/build:
- Screen size and scaling:
- Browser and version:
- Authenticator application:
- Test email inbox:
- PayPal sandbox buyer:
- Started UTC:
- Completed UTC:

Do not record passwords, authentication secrets, recovery codes, payment credentials,
full session tokens, or unredacted customer information.

## Entry requirements

- [ ] **CRITICAL:** Source certification passed for the exact commit and installer.
- [ ] **CRITICAL:** Sandbox preflight passed.
- [ ] **CRITICAL:** All five sandbox websites return their expected response.
- [ ] **CRITICAL:** The sandbox database contains isolated test scenarios only.
- [ ] **CRITICAL:** PayPal uses Sandbox and email uses an allowlisted test inbox.
- [ ] The release-candidate installer and checksum are available from the sandbox.
- [ ] The tester has only public website documentation and no developer instructions.
- [ ] Screen recording or timestamped screenshots are ready.

## E2E-01 — Discover and select a license

Persona: first-time POS developer or technician.

- [ ] Find the product from the sandbox homepage.
- [ ] Understand what the emulator does without opening internal documentation.
- [ ] Identify Windows 11 Pro, ESC/POS, TCP/IP, and Port 9100 requirements.
- [ ] Compare Trial, Lite, Pro, and Enterprise features and listener allowances.
- [ ] Understand permanent-license and Maintenance and Support terms.
- [ ] Select Lite, Pro, or Enterprise and arrive at Customer Portal authentication.
- [ ] Confirm the selected license remains visible after redirect.
- [ ] Confirm pricing and feature wording agree across website, pricing, Buy, and portal.

Pass criteria: the tester selects the intended license without assistance or conflicting
information.

## E2E-02 — Create and verify the customer account

- [ ] Create an account using a new allowlisted test address.
- [ ] Enter full name and optional company information.
- [ ] Confirm clear privacy, service, analytics, and marketing choices.
- [ ] Receive the verification message with correct branding, sender, subject, links,
  preview text, and no reply invitation.
- [ ] Verify the email using the single-use link.
- [ ] Create a password and sign in.
- [ ] Confirm expired, reused, or altered verification links provide a recovery action.

Pass criteria: the account is verified and usable without administrator intervention.

## E2E-03 — Password recovery, session, and two-factor authentication

- [ ] Sign out and sign back in.
- [ ] Request password recovery and receive the correct email.
- [ ] Reset the password and confirm the link cannot be reused.
- [ ] Configure two-factor authentication using the QR code.
- [ ] Sign in using an authenticator code.
- [ ] Confirm recovery-code behavior.
- [ ] Confirm ten minutes of inactivity signs the customer out.
- [ ] Disable MFA using password, code/recovery code, confirmation, session revocation,
  and notification.
- [ ] Perform the administrator recovery flow and confirm forced reenrollment.

Pass criteria: account recovery works securely and every failure explains the next step.

## E2E-04 — Download, install, and begin Trial

- [ ] Download the exact release-candidate installer from the sandbox.
- [ ] Confirm Windows identifies the expected publisher/application.
- [ ] Read and accept the EULA.
- [ ] Complete installation without downloading another dependency.
- [ ] Launch the application and background service successfully.
- [ ] Confirm the first-run experience starts in Trial automatically.
- [ ] Confirm the application offers **Link This Computer** without an activation key.
- [ ] Complete the setup wizard using published instructions only.

Pass criteria: a new customer reaches a working Trial without developer assistance.

## E2E-05 — Use the application in Trial

- [ ] Configure a listener and confirm its health.
- [ ] Send an external ESC/POS print job over TCP/IP Port 9100.
- [ ] Review Activity, receipt preview, Commands, Raw data, and Job details.
- [ ] Use panel collapse, zoom, clear job, and clear-all controls.
- [ ] Confirm the TRIAL watermark.
- [ ] Confirm five external jobs per local day are permitted and the sixth is blocked.
- [ ] Confirm built-in test receipts do not consume the daily allowance.
- [ ] Confirm Trial history and premium-feature restrictions.
- [ ] Confirm errors and unsupported commands provide actionable information.

Pass criteria: Trial behavior matches the published feature comparison exactly.

## E2E-06 — Purchase each paid license

Run separate isolated journeys for Lite, Pro, and Enterprise.

- [ ] Start the purchase from the selected license.
- [ ] Sign in or register and return to the preserved selection.
- [ ] Complete PayPal Sandbox checkout.
- [ ] Confirm success, cancellation, decline/failure, and retry behavior.
- [ ] Confirm duplicate capture/webhook delivery does not duplicate the purchase.
- [ ] Confirm the purchase appears in Customer Portal and Admin Portal.
- [ ] Confirm payment amount, currency, tier, PayPal reference, customer, and
  correlation ID agree.
- [ ] Receive and verify the purchase/welcome email.
- [ ] Confirm the purchase-confirmation email visibly includes the POS Printer
  Emulator logo and attaches a readable, logo-branded PDF invoice with a stable
  invoice number, correct customer, date, description, amount, currency, paid
  status, and transaction reference.
- [ ] Confirm Customer Portal Billing exposes both the invoice and receipt only to the
  authenticated customer who owns the purchase.

Pass criteria: one payment produces one correct entitlement for the verified account.

## E2E-07 — Link the computer and apply the license

- [ ] Select **Link This Computer** in the application.
- [ ] Sign in to Customer Portal and inspect the pending computer details.
- [ ] Approve the correct computer.
- [ ] Reject expired, reused, altered, and cross-account codes.
- [ ] Select an eligible entitlement.
- [ ] Confirm the application applies the license without reinstalling.
- [ ] Confirm application, Customer Portal, and Admin Portal show the same tier,
  computer, active status, listener allowance, and Maintenance and Support date.
- [ ] Restart the application and Windows and confirm the license remains active.

Pass criteria: account and device verification automatically apply the purchased tier.

## E2E-08 — Validate Lite, Pro, and Enterprise behavior

- [ ] Lite supports one printer listener and every published Lite feature.
- [ ] Pro supports up to two listeners and every published Pro feature.
- [ ] Enterprise supports up to fifteen listeners and Enterprise-only diagnostics and
  reporting.
- [ ] Lower tiers cannot bypass listener or premium-feature restrictions.
- [ ] Feature locks explain the eligible upgrade rather than displaying a generic error.
- [ ] Light and dark modes remain usable for each tier.

Pass criteria: entitlements are consistent, enforceable, and accurately marketed.

## E2E-09 — Upgrade, transfer, reinstall, and recover

- [ ] Complete Lite-to-Pro, Lite-to-Enterprise, and Pro-to-Enterprise upgrades.
- [ ] Confirm features synchronize without reinstalling.
- [ ] Deactivate the original computer in Customer Portal.
- [ ] Link a second computer and apply the released entitlement.
- [ ] Confirm device-limit enforcement and transfer history.
- [ ] Test support-assisted recovery when the original computer is unavailable.
- [ ] Upgrade-install over the oldest supported version.
- [ ] Rename the computer and test another Windows user.
- [ ] Uninstall, reinstall, sign in, and restore eligible licensing.

Pass criteria: legitimate changes preserve ownership without enabling device-limit
bypass or duplicate entitlements.

## E2E-10 — Maintenance, updates, and renewal

- [ ] Confirm the exact Maintenance and Support expiration date.
- [ ] Confirm the three-month reminder, days remaining, and renewal action.
- [ ] Simulate expiration and confirm purchased permanent features keep working.
- [ ] Confirm expired coverage blocks ineligible update downloads and protected
  recovery actions at both UI and API layers.
- [ ] Complete a PayPal Sandbox renewal.
- [ ] Confirm the date extends correctly and eligible update/support access returns.
- [ ] Confirm an available version shows installed/latest versions, versions behind,
  release date, release notes, and the correct action.

Pass criteria: permanent ownership and optional renewal behavior match published terms.

## E2E-11 — Support and customer communications

- [ ] Submit a support request from Customer Portal.
- [ ] Confirm the reference, status, diagnostics handling, and Admin Portal record.
- [ ] Verify support confirmation, maintenance reminder, release, promotion,
  inactive-user, MFA, and upgrade templates as applicable.
- [ ] Confirm sender selection by Service versus Purchase/Marketing tags.
- [ ] Confirm documentation, Help Center, and support-request buttons.
- [ ] Confirm mobile and desktop layouts, branding, placeholders, links, and footer.
- [ ] Confirm no template invites a reply to an unmonitored mailbox.
- [ ] Confirm frequency caps, consent, and inactive-user stop conditions.

Pass criteria: messages arrive once, contain correct data, and lead to a working action.

## E2E-12 — Failure and recovery

- [ ] Interrupt licensing service access and validate offline grace and recovery.
- [ ] Simulate API/database timeouts without exposing internal diagnostics.
- [ ] Delay payment webhooks and reconcile the entitlement.
- [ ] Simulate email-provider failure and confirm customer actions are retained.
- [ ] Test port conflict, malformed ESC/POS data, unsupported command, and listener
  failure recovery.
- [ ] Restore service and confirm manual/automatic synchronization.

Pass criteria: failures preserve customer data and provide a specific recovery path.

## E2E-13 — Accessibility and responsive experience

- [ ] Complete primary website and portal journeys using keyboard only.
- [ ] Confirm visible focus, labels, reading order, and meaningful alternative text.
- [ ] Check contrast in light and dark modes.
- [ ] Test common desktop, tablet, and mobile widths.
- [ ] Confirm no horizontal scrolling, clipped tables, overlapping controls, or
  inaccessible modal actions.
- [ ] Confirm zoom at 200% remains usable.

Pass criteria: primary customer journeys remain understandable and operable.

## E2E-14 — Administrative reconciliation and audit

- [ ] Confirm customer, purchase, entitlement, computer, maintenance, email, support,
  usage, and geographic records agree with the customer journey.
- [ ] Confirm every security, payment, entitlement, and administrative change is
  audited with actor, UTC time, reason, affected entity, outcome, and correlation ID.
- [ ] Confirm secrets, full tokens, payment credentials, and authenticator secrets are
  never displayed.
- [ ] Confirm dashboard counts reconcile to the completed journey.

Pass criteria: an administrator can explain the entire journey from auditable records.

## E2E-15 — First-time customer comprehension

After the functional run, ask the tester without coaching:

- What does the product do?
- Which license fits your use?
- Is the license permanent?
- What does Maintenance and Support provide?
- How do you link or transfer a computer?
- How do you obtain help?
- What would you do after an installation, payment, or licensing error?

- [ ] The tester answers each question accurately using customer-facing material.
- [ ] Record every point of confusion as a documentation or product defect.
- [ ] Record time to first receipt, time to purchase, and time to licensed operation.

Pass criteria: no critical concept requires undocumented explanation.

## Required evidence

For every E2E journey, attach:

- timestamped screenshot or video;
- expected and actual result;
- tester and Windows/browser context;
- redacted correlation ID and relevant provider reference;
- redacted logs or database assertion when applicable; and
- linked defect plus retest evidence for every failure.

## Exit decision

- [ ] All entry requirements passed.
- [ ] E2E-01 through E2E-15 passed.
- [ ] All critical defects are closed and retested.
- [ ] Installer, commit, version, and SHA-256 still match the technical certification.
- [ ] Test evidence is no older than seven days.
- [ ] Test coordinator approves the result.
- [ ] Release owner accepts the customer-experience result.

Decision:

- [ ] PASS — customer-experience gateway satisfied.
- [ ] FAIL — production rollout blocked.

First-time tester:

Test coordinator:

Release owner:

Completed UTC:
