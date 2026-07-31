# Manual first-time customer certification

Complete this checklist in the isolated staging environment. Use a tester who has not
worked on the implementation and give them no undocumented instructions.

## Test identity

- Release candidate:
- Installer SHA-256:
- Tester:
- Started UTC:
- Completed UTC:
- Correlation ID:
- License journey: Trial / Lite / Pro / Enterprise
- Windows 11 Pro build:

## Customer journey

- [ ] Find and compare licenses from the public staging website.
- [ ] Select the intended license and reach Customer Portal registration.
- [ ] Create an account and verify the test email.
- [ ] Download and install the release-candidate installer.
- [ ] Complete onboarding without assistance.
- [ ] Complete the PayPal sandbox purchase.
- [ ] Confirm the purchase and license appear in the Customer Portal.
- [ ] Use **Link This Computer** and approve the displayed computer.
- [ ] Confirm the application applies the correct license automatically.
- [ ] Confirm the correct features and listener allowance.
- [ ] Confirm no activation-key field or instruction is visible.
- [ ] Restart the application and confirm the license remains active.
- [ ] Restart Windows and confirm the license remains active.
- [ ] Unlink the computer and confirm the application returns to Trial Mode.
- [ ] Relink the eligible license and confirm reactivation.
- [ ] Review every generated email on desktop and mobile.
- [ ] Confirm Admin Portal purchase, license, computer, communication, and audit records.

## Experience review

- [ ] Instructions use plain language.
- [ ] Error messages identify a specific recovery action.
- [ ] Pricing and permanent-license terms are clear.
- [ ] Maintenance and Support terms and expiration are clear.
- [ ] No unexpected horizontal scrolling, overlap, or unreadable content.
- [ ] No customer action requires developer or support assistance.

## Defects and evidence

Record screenshots, videos, payment references, email message IDs, relevant redacted
logs, expected results, actual results, and linked defects in the certification report.

## Decision

- [ ] Pass — eligible for release.
- [ ] Fail — release blocked.

Release owner:

Customer-experience tester:
