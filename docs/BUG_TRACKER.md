# POS Printer Emulator bug tracker

This document is the single status view for reported, confirmed, fixed, and released defects. Feature planning belongs in the [release tracker](RELEASE_TRACKER.md), while completed release notes belong in [CHANGELOG.md](../CHANGELOG.md).

GitHub Issues and GitHub Projects are the official working system for bug reports and triage. This file remains the repository-owned defect summary. Public issues must not contain activation keys, customer registration data, private logs, or receipt contents.

## Status definitions

| Status | Meaning |
| --- | --- |
| Reported | A problem was reported but has not yet been reproduced or confirmed. |
| Confirmed | The problem is reproducible and its expected behavior is understood. |
| In progress | A correction is being implemented. |
| Fixed locally | The correction and relevant tests pass locally but have not been publicly released. |
| Released | The correction is included in a published installer and has completed release verification. |
| Deferred | The problem is valid but intentionally postponed with a documented reason. |
| Closed — not a bug | Investigation found expected behavior, an environmental problem, or insufficient evidence. |

## Severity definitions

| Severity | Meaning | Target handling |
| --- | --- | --- |
| Critical | Security exposure, data loss, widespread installation failure, or application unusable for most customers | Stop normal feature work and prepare an emergency fix |
| High | Core printing, activation, updating, installation, or paid functionality fails with no reasonable workaround | Prioritize before the next planned feature release |
| Medium | Important behavior is incorrect but a workaround exists | Schedule into the next suitable release |
| Low | Cosmetic, minor usability, or uncommon compatibility problem | Bundle with planned maintenance work |

## Open bugs

There are currently no documented open bugs. New reports must be entered here before implementation begins.

## Resolved bugs

| Bug ID | Severity | Summary | Affected version(s) | Fixed in | Status | Verification |
| --- | --- | --- | --- | --- | --- | --- |
| BUG-001 | High | The updater reused a locked temporary installer file, preventing an available update from starting. | v0.3.05 and earlier updater implementation | v0.3.06 | Released | Installer downloads use a unique staging directory and close the file before launch. |
| BUG-002 | Medium | Existing registration values were requested again during an upgrade instead of being reused or prefilled. | v0.3.06 and earlier installer implementation | v0.3.07 | Released | Valid saved registration skips the page; partial saved registration is prefilled. |
| BUG-003 | Medium | Recognized Epson NV graphic print commands were reported as unsupported when printer-resident image data was unavailable. | v0.3.12 and earlier parser behavior | v0.3.13 | Released | Recognized missing stored images are informational and matching imported logos render correctly. |
| BUG-004 | High | Completed emulated print-job totals did not reliably reach the owner dashboard through the canonical telemetry endpoint. | v0.3.13 telemetry implementation | v0.3.14 | Released | Reports use the canonical HTTPS endpoint and pending totals are retained and retried after temporary failures. |
| BUG-005 | Medium | Text, Raw, and Capture exports navigated the desktop WebView away from the receipt viewer and displayed a `ConnectionAborted` startup error instead of saving in place. | v0.3.15 | v0.3.16 | Released | Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors. |

## Bug record template

Copy this section for every new report:

```text
Bug ID: BUG-###
Title:
Status: Reported
Severity:
Reported on:
Reported by:
Affected version(s):
Environment:

Customer impact:

Expected behavior:

Actual behavior:

Reproduction steps:
1.
2.
3.

Evidence:
- Screenshot or video:
- Capture/bin file:
- Relevant logs:

Cause:

Planned correction:

Regression tests:

Target release:
Fixed in:
Verification result:
```

## Bug-fix workflow

1. Assign the next sequential `BUG-###` identifier immediately when a report is received.
2. Preserve customer screenshots, capture files, logs, and reproduction details without committing private customer data to the public repository.
3. Reproduce the problem and move it from **Reported** to **Confirmed** before choosing a correction, unless an urgent security or data-loss issue requires immediate containment.
4. Assign severity based on customer impact, not implementation difficulty.
5. Add a regression test whenever the behavior can be tested automatically.
6. Record the target release in both this tracker and the [release tracker](RELEASE_TRACKER.md).
7. Move the bug to **Fixed locally** only after the correction and verification pass.
8. Add the bug ID to `CHANGELOG.md` and the GitHub Release notes.
9. Move the bug to **Released** only after the public installer, website download, and in-application update path have been verified.
10. Keep the GitHub issue, this file, and the protected Admin **Dev Support** Bug Tracker on the same status.

## Version handling

- Normal bug fixes are included in the next scheduled `v0.3.xx` release and identified by bug ID in its release notes.
- A Critical or High defect that cannot wait receives the next available `v0.3.xx` version as a dedicated maintenance release; later planned releases move forward without reusing that version.
- Published version numbers are never reused or silently replaced.
