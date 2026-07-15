# POS Printer Emulator architecture

## Current vertical slice

```text
POS terminal
    | RAW ESC/POS over TCP 9100
    v
TcpReceiptListener -> EscPosJobFramer -> ReceiptProcessor
                                           |-> LicenseService
                                           |-> EscPosParser
                                           `-> ReceiptStore
                                               |-> Trial: session only
                                               `-> Full: persistent history
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

The viewer binds to `127.0.0.1` while the printer listener binds to `0.0.0.0` by default. The WPF application embeds the viewer in a normal desktop window through Microsoft WebView2; the local URL remains available for diagnostics. Trial receipts remain only in process memory. Full-Version receipts are persisted under the service-owned ProgramData directory with a 500-job retention limit.

## Licensing boundary

New installations store the customer/company name and email address in `%ProgramData%\POSPrinterEmulator`. Trial usage is counted by local calendar day. Activation keys use ECDSA P-256 signatures and are tied to a normalized hash of both registration fields. The application contains only the vendor public key; the private key remains outside the repository and installer in the vendor's secure key folder.

The local activation API validates the signed key, persists it, enables Full Mode, loads any existing Full-Version history, removes the trial watermark, and unlocks premium controls immediately. Editing a local license record cannot create a valid signature.

The self-contained C# service executable also owns the Windows installation lifecycle. Inno Setup invokes its `--install-windows` and `--uninstall-windows` modes to create or remove the Windows Service, configure the private/domain TCP 9100 firewall rule, verify viewer health, and remove service-owned data. Setup also checks for WebView2 and installs the bundled Microsoft bootstrapper when it is missing.

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
2. **v0.3.16 — Printer profiles.** Model paper width, code pages, supported commands, status behavior, and rendering defaults as selectable printer configurations.
3. **v0.3.17 — Multiple printer listeners.** Host independently configured printer endpoints with separate names, ports, profiles, status state, and activity filtering.
4. **v0.3.18 — Receipt comparison and automated validation.** Compare raw bytes, parsed commands, and deterministic render output with saved baselines and machine-readable pass/fail results.
5. **v0.3.19 — Enhanced support package and connection diagnostics.** Provide guided listener, port, firewall, and connectivity tests plus privacy-aware diagnostic bundles suitable for customer support.
6. Service-to-viewer authentication and installer repair mode.
7. SQLite history, retention controls, deletion, and migrations.
8. Optional online activation revocation and license transfer workflow.
9. Hardened Thermal adapter with image, QR, barcode, and code-page parity.
10. PNG export and deterministic PDF generation.
11. Production code-signing and expanded unattended deployment validation.
