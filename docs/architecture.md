# POS Printer Emulator architecture

## Current vertical slice

```text
POS terminals
    | RAW ESC/POS over one or more TCP ports
    v
PrinterListenerManager
    |-> PrinterListenerRuntime [default :9100]
    |-> PrinterListenerRuntime [paid listener N]
    `-> per-listener profile, printer state, buffer, counters, and lifecycle
                     |
                     v
             EscPosJobFramer -> ReceiptProcessor
                                    |-> LicenseService
                                    |-> EscPosParser
                                    `-> ReceiptStore
                                        |-> Trial: session only
                                        `-> Lite/Pro/Enterprise: SQLite history
                                             |
                                             v
                               localhost ASP.NET Core API
                                             |
                                             v
                               React operations viewer
                                             |
                                             v
                           C# WPF desktop shell (WebView2)
```

The viewer binds to `127.0.0.1` while the compatible default printer listener binds to `0.0.0.0:9100`. The v0.3.25 allowance model permits one total listener for Trial, one for Lite, two for Pro, and up to 15 for Enterprise. Managed listeners use unique ports, profiles, simulated printer state, optional bounded buffers, counters, and lifecycle controls. The manager owns each runtime separately, so a port conflict or failure on one listener does not stop the others.

The WPF application embeds the viewer in a normal desktop window through Microsoft WebView2; the local URL remains available for diagnostics. Trial receipts remain only in process memory. Lite, Pro, and Enterprise receipts are persisted in `%ProgramData%\POSPrinterEmulator\pos-printer-emulator.db` with a 500-job retention limit. SQLite stores managed-listener configuration and immutable listener ID, name, and port snapshots with each job. WAL, schema migrations, transactions, and listener indexes require no database service or customer configuration. Existing JSON history is copied to a verified rollback backup before migration, and existing databases upgrade transactionally without losing history.

## Licensing boundary

New installations store the customer/company name and email address in `%ProgramData%\POSPrinterEmulator`. Trial usage is counted by local calendar day. Activation keys use ECDSA P-256 signatures and are tied to a normalized hash of both registration fields. The application contains only the vendor public key; the private key remains outside the repository and installer in the vendor's secure key folder.

The local activation API validates the signed key, persists it, enables the purchased Lite, Pro, or Enterprise level, loads any existing paid history, removes the trial watermark, and unlocks the authorized controls immediately. All paid tiers receive the same paid feature set. Listener configuration and runtime authorization enforce total allowances of Trial 1, Lite 1, Pro 2, and Enterprise 15. Editing a local license record cannot create a valid signature.

v0.3.26 adds a separate maintenance entitlement to the permanent license boundary. The signed license continues authorizing the purchased application features indefinitely; maintenance dates authorize only application update checking/downloads and assisted technical support. New paid purchases receive 12 months from purchase, pre-v0.3.26 paid licenses are grandfathered through 2027-07-19, early renewal extends the existing end date, and lapsed renewal begins on its confirmed payment date. Cached or signed entitlement proof keeps receipt emulation independent from an entitlement-service outage, and local diagnostics remain available even when maintenance is expired.

The self-contained C# service executable also owns the Windows installation lifecycle. Inno Setup invokes its `--install-windows` and `--uninstall-windows` modes to create or remove the Windows Service, configure a private/domain program-scoped RAW TCP firewall rule, verify viewer health, and remove service-owned data. The program rule covers validated Enterprise listener ports without exposing the localhost viewer or requiring per-port customer firewall changes. Setup also checks for WebView2 and installs the bundled Microsoft bootstrapper when it is missing.

Repository automation is provided by the `tools/ReceiptLab.Build` .NET console project. It coordinates the viewer build, service and desktop builds, tests, self-contained publish, prerequisite packaging, installer compilation, and sample TCP sender without PowerShell scripts.

## Thermal integration boundary

The first vertical slice uses a defensive managed parser so it can run without a Rust toolchain. The parser is registered behind one service type (`EscPosParser`). A hardened fork of `zachzurn/thermal` can replace this implementation through an isolated local renderer process once it provides:

- structured, public error fields;
- original byte start/end offsets;
- panic-free malformed-input handling;
- configurable TM-T88V printer profiles;
- a stable JSON or C ABI contract; and
- golden-output and fuzz coverage.

Keeping the Rust renderer out-of-process initially prevents a parser failure from terminating the TCP service.

## Next production increments

Detailed scope, completion criteria, priority reasons, and current status are maintained in the [release tracker](RELEASE_TRACKER.md).

1. **v0.3.15 — Capture, import, export, and replay.** Preserve complete ESC/POS sessions, import external binary jobs, export portable capture packages, and replay captured jobs through the processing pipeline.
2. **v0.3.16 — In-place receipt export correction.** Save Text, Raw, and Capture files without replacing the loaded desktop viewer or showing an attachment-navigation startup error.
3. **v0.3.17 — License tiers and Pro feature gates.** Establish Trial, Pro, and Enterprise licensing with paid-feature authorization boundaries.
4. **v0.3.18 — Admin Portal and tier-aware purchase pricing.** Manage separate Pro and Enterprise prices, purchases, fulfillment, and activation keys.
5. **v0.3.19 — Printer profiles.** Model paper width, code pages, supported commands, status behavior, and rendering defaults as selectable printer configurations.
6. **v0.3.20 — Reliable SQLite receipt history.** Persist Pro and Enterprise history transactionally, migrate legacy JSON after a verified rollback backup, keep Trial session-only, and prepare listener-ready indexes.
7. **v0.3.21 — Enterprise multiple printer listeners (released 2026-07-18).** Host independently configured Enterprise printer endpoints with separate names, ports, profiles, status state, buffers, counters, and Activity filtering while Trial and Pro keep one listener.
8. **v0.3.22 — Receipt workflow regression fixes (released 2026-07-18).** Restore near-instant Test Receipt display and reliable Clear All deletion when obsolete legacy history files are locked.
9. **v0.3.23 — Activation and Printer Setup Wizard fixes (released 2026-07-19).** Keep valid Enterprise activation independent from optional storage recovery and create Windows printer queues through the native printer API instead of assigning the read-only WMI printer name.
10. **v0.3.24 — Upgrade licensing and Printer Setup safeguards (released 2026-07-19).** Preserve paid licensing through updates, make license persistence compatible with hardened Windows data folders, give Trial users safe activation diagnostics, and allocate unique Windows printer ports sequentially from 9100 with matching Enterprise listeners.
11. **v0.3.25 — Four-tier licensing and upgrade paths (released 2026-07-19).** Introduce Lite at $24.99, retain the common paid feature set, and enforce total listener allowances of Trial 1, Lite 1, Pro 2, and Enterprise 15.
12. **v0.3.26 — Annual Application Maintenance and Support (released 2026-07-20).** Keep paid licenses permanent while adding the included first year, optional one-time annual renewals, maintenance-aware updates and assisted support, and grandfathered existing-customer coverage.
13. **v0.3.33 — Enhanced support package and connection diagnostics.** Provide guided service, listener, port, firewall, Windows queue, Epson driver, storage, and connectivity checks; reviewed repair actions; and privacy-aware support packages suitable for customers on every license tier.
14. **v0.3.34 — Encrypted backup, EULA, and support policy.** Protect portable listener, profile, stored-logo, printer-state, preference, and optional paid-history backups with authenticated password encryption, reviewed exclusions, safety snapshots, and rollback-safe restore; require installer EULA acceptance and align the public Windows and support boundaries.
15. **v0.3.35 — Receipt comparison and automated validation.** Compare raw bytes, parsed commands, and deterministic render output with saved baselines and machine-readable pass/fail results.
16. **v0.3.36 — Guided update installation and restart.** Verify downloaded installers, create a pre-update safety snapshot, confirm downtime, shut down cleanly, run an external updater, preserve state, recover from failure, and relaunch automatically.
17. Service-to-viewer authentication and installer repair mode.
18. Advanced SQLite maintenance, configurable retention, repair, backup, and restore.
19. Optional online activation revocation and license transfer workflow.
20. Hardened Thermal adapter with image, QR, barcode, and code-page parity.
21. PNG export and deterministic PDF generation.
22. Production code-signing and expanded unattended deployment validation.
