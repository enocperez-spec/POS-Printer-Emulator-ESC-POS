# Receipt Lab architecture

## Current vertical slice

```text
POS terminal
    | RAW ESC/POS over TCP 9100
    v
TcpReceiptListener -> EscPosJobFramer -> ReceiptProcessor
                                           |-> TrialGate
                                           |-> EscPosParser
                                           `-> ReceiptStore (session only)
                                                    |
                                                    v
                                      localhost ASP.NET Core API
                                                    |
                                                    v
                                           React operations viewer
```

The viewer binds to `127.0.0.1` while the printer listener binds to `0.0.0.0` by default. Trial receipts remain only in process memory. The persisted trial counter contains no receipt content.

The self-contained C# executable also owns the Windows installation lifecycle. Inno Setup invokes its `--install-windows` and `--uninstall-windows` modes to create or remove the Windows Service, configure the private/domain TCP 9100 firewall rule, verify viewer health, and remove service-owned data.

Repository automation is provided by the `tools/ReceiptLab.Build` .NET console project. It coordinates the viewer build, application build, tests, self-contained publish, installer compilation, and sample TCP sender without PowerShell scripts.

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

1. Service-to-viewer authentication and installer repair mode.
2. SQLite activated-mode history, retention, deletion, and migrations.
3. Signed online/offline activation tokens and license transfer workflow.
4. Hardened Thermal adapter with image, QR, barcode, and code-page parity.
5. PNG export and deterministic PDF generation.
6. Production code-signing and expanded unattended deployment validation.
