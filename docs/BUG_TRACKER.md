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

| Bug ID | Severity | Summary | Affected version(s) | Target | Status | Verification |
| --- | --- | --- | --- | --- | --- | --- |

There are no open bugs currently assigned to the v0.3.35 release.

## Resolved bugs

| Bug ID | Severity | Summary | Affected version(s) | Fixed in | Status | Verification |
| --- | --- | --- | --- | --- | --- | --- |
| BUG-001 | High | The updater reused a locked temporary installer file, preventing an available update from starting. | v0.3.05 and earlier updater implementation | v0.3.06 | Released | Installer downloads use a unique staging directory and close the file before launch. |
| BUG-002 | Medium | Existing registration values were requested again during an upgrade instead of being reused or prefilled. | v0.3.06 and earlier installer implementation | v0.3.07 | Released | Valid saved registration skips the page; partial saved registration is prefilled. |
| BUG-003 | Medium | Recognized Epson NV graphic print commands were reported as unsupported when printer-resident image data was unavailable. | v0.3.12 and earlier parser behavior | v0.3.13 | Released | Recognized missing stored images are informational and matching imported logos render correctly. |
| BUG-004 | High | Completed emulated print-job totals did not reliably reach the owner dashboard through the canonical telemetry endpoint. | v0.3.13 telemetry implementation | v0.3.14 | Released | Reports use the canonical HTTPS endpoint and pending totals are retained and retried after temporary failures. |
| BUG-005 | Medium | Text, Raw, and Capture exports navigated the desktop WebView away from the receipt viewer and displayed a `ConnectionAborted` startup error instead of saving in place. | v0.3.15 | v0.3.16 | Released | Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors. |
| [BUG-006](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/8) | Medium | The listener manager could be disposed twice during host shutdown, raising an unhandled `ObjectDisposedException` after all listeners had already stopped. | v0.3.21 development build | v0.3.21 | Released | Disposal is idempotent, the regression test calls hosted-service stop followed by disposal twice, all 79 tests pass, and live Ctrl+C shutdown stops both listeners without an unhandled exception. |
| BUG-007 | Medium | Test Receipt display regressed from nearly instant to approximately three seconds. | v0.3.21 | v0.3.22 | Released | The sample endpoint returns the complete receipt, the UI selects it immediately, Activity refreshes in the background, and end-to-end display completed in 280 ms. |
| BUG-008 | High | Delete All Print Jobs returned HTTP 500 and left jobs visible when obsolete legacy history cleanup encountered a locked file. | v0.3.21 | v0.3.22 | Released | SQLite deletion remains authoritative, locked legacy cleanup is best effort, a locked-file regression test passes, all 80 tests pass, and end-to-end Clear All completed in 285 ms without an error banner. |
| [BUG-009](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/13) | High | Activating a valid Enterprise key returned HTTP 500 when optional paid-history or listener storage initialization failed. | v0.3.22 | v0.3.23 | Released | Activation succeeds independently of optional storage recovery, malformed keys fail safely, regression tests cover forced storage failures, and the complete 83-test suite passes. |
| [BUG-010](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/14) | High | Printer Setup Wizard failed with `System.Management.ManagementException: Invalid parameter` while creating the Windows printer queue. | v0.3.22 and earlier wizard implementation | v0.3.23 | Released | Queue creation uses the native Windows `AddPrinter` API with the required driver, port, print processor, and RAW data type; an installed Windows test created the Epson queue on `127.0.0.1:9100` and sent the wizard Test Receipt successfully. |
| [BUG-011](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/16) | High | Updating could leave a paid installation in Trial, and reactivation could fail when Windows allowed overwriting the existing license file but denied temporary-file replacement. Trial users were then directed to the paid-only Support page. | v0.3.23 | v0.3.24 | Released | License writes use a compatible direct-write fallback; setup retains and restores the last known-good registration plus activation pair across retries; entered registration is validated against a surviving paid key before any write; ownership and inheritance are repaired; paid license mode is verified through the updated service before recovery files are removed; startup retries persisted state; and Trial can download privacy-safe Activation Diagnostics. All 105 tests pass, and installed Enterprise upgrade plus maintenance-reinstall tests preserved the customer and license without reactivation. |
| [BUG-012](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/17) | Medium | Printer Setup Wizard could assign the same TCP/IP port number to more than one Windows printer instead of selecting the next available port. | v0.3.23 and earlier | v0.3.24 | Released | Port selection reads machine-wide Windows printer and TCP/IP-port assignments, excludes an idempotent same-printer reinstall, reserves emulator listeners whose bind address or profile cannot serve the requested Epson endpoint, selects the first free port from 9100 upward, displays it in the summary, creates or reuses the matching Enterprise listener, and rechecks throughout installation. The installed test selected 9101 beside an existing 9100 queue, created the Epson queue, delivered a 112-byte test receipt, and then identified 9102 as next available. |
| BUG-013 | Medium | The always-available Support diagnostic download returned HTTP 500 if the optional Stored Logos directory was absent. | v0.3.26 development build | v0.3.26 | Released | Missing logo storage is treated as an empty store, imports recreate it safely, six Stored Graphic tests pass, and live expired-maintenance verification returned a privacy-safe diagnostic file with HTTP 200 while update checks remained HTTP 403. |

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
