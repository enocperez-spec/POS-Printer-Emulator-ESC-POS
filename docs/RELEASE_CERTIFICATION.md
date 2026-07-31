# End-to-end release certification

POS Printer Emulator releases use two certification layers:

1. **Source certification** runs for every pull request and `main` update.
2. **Staging journey certification** runs against isolated services and a Windows 11 Pro
   release-candidate machine before a public release is created.

The source gate and isolated sandbox web environment are implemented. The five sandbox
hosts, sandbox MariaDB schema, deterministic certification data, PayPal sandbox
configuration, and protected deployment recovery are available. Provider-backed email
execution and automated Windows restart testing remain disabled until an allowlisted
test inbox and a resettable Windows 11 Pro runner are connected.

The staging database control plane and journey-correlation schema are implemented.
Hosted IONOS databases can be initialized and seeded through the authenticated sandbox
Admin endpoint without exposing database or administrator credentials.

The sandbox web surfaces are isolated as follows:

- `sandbox.posprinteremulator.com` → `sandbox_posprinteremulator`
- `userportal-sandbox.posprinteremulator.com` → `userportal-sandbox-posprinteremulator`
- `admin-sandbox.posprinteremulator.com` → `admin_sandbox_posprinteremulator`
- `buy-sandbox.posprinteremulator.com` → `buy_sandbox_posprinteremulator`
- `support-sandbox.posprinteremulator.com` → `support_sandbox_posprinteremulator`

## Source certification

Run:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- local-gate
```

The command:

- restores and builds the React viewer;
- builds the service, desktop shell, and updater in Release configuration;
- runs all C# tests;
- runs every PHP contract suite;
- verifies public release metadata and SEO;
- validates the maintained journey matrix;
- scans customer-facing application, installer, portal, purchase, and website sources
  for prohibited activation-key language; and
- creates redacted JSON and HTML evidence below `artifacts/certification/`.

To validate a release-candidate installer too:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- local-gate --installer artifacts/installer/POSPrinterEmulatorSetup-VERSION-win-x64.exe
```

The GitHub `Release certification source gate` workflow uploads the generated evidence
even when a critical check fails.

## Staging safety preflight

Copy `certification/staging.example.json` to an untracked deployment-specific location.
Replace only the non-secret staging URLs. Store all credential values in the protected
environment variables named by the file.

Run:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- preflight --config C:\secure\ppe-staging.json
```

Preflight fails closed when:

- the environment is not named `staging`;
- production hosts are allowed;
- a service URL is not HTTPS;
- a production POS Printer Emulator or PayPal API host is used; or
- a required protected variable is missing.

Never put PayPal, email, database, SFTP, or administrator credentials in the JSON file.

## Staging database reset and seed

The C# database tool can rebuild an isolated staging database and seed Trial, Lite, Pro,
Enterprise, and expired-maintenance scenarios:

```text
dotnet run --project tools/POSPrinterEmulator.DatabaseTool --configuration Release -- staging-rebuild-and-seed database/schema.sql
```

The command uses `PPE_CERT_DB_HOST`, `PPE_CERT_DB_PORT`, `PPE_CERT_DB_USER`,
`PPE_CERT_DB_PASSWORD`, and `PPE_CERT_DB_NAME`. It refuses to connect until all of
these destructive-operation guards pass:

- `PPE_ENVIRONMENT` is exactly `staging`;
- `PPE_STAGING_DATABASE_LABEL` contains a distinct `staging` or `sandbox`
  segment. This explicit logical label is required because IONOS database
  identifiers such as `dbs12345678` do not carry a human-readable environment
  name;
- `PPE_STAGING_ALLOWED_HOST` exactly matches `PPE_CERT_DB_HOST`; and
- `PPE_STAGING_RESET_CONFIRM` exactly matches `RESET:<database name>`.

`PPE_STAGING_EMAIL_DOMAIN` controls the non-production addresses assigned to seeded
records. It should point only to the captured staging inbox domain. The default is
`example.invalid`, which cannot deliver email.

## Journey correlation

`database/migrate-journey-correlation.sql` adds a UUID correlation identifier to
registration, password recovery, checkout, purchase, email, device-linking, activation,
and audit records. The Customer Portal creates the identifier, the Buy service carries
it into the PayPal `custom_id`, and the desktop computer-link client retains it through
approval and completion. Brevo delivery events inherit the identifier from their
outbox message.

## Journey evidence

Each run receives a unique certification run ID and correlation ID. Reports contain:

- product version and Git commit;
- environment and timestamps;
- each critical check and duration;
- redacted command output; and
- the overall pass/fail decision.

Provider references, screenshots, application logs, email events, database assertions,
and Windows restart results will be added by the staging and Windows journey runners.
They must use the same correlation ID.

## Release policy

A release is not certified until:

- source certification passes;
- Trial, Lite, Pro, and Enterprise staging journeys pass;
- PayPal sandbox purchase, cancellation, failure, refund, and idempotency checks pass;
- expected test emails are captured and validated;
- clean install, application restart, Windows restart, upgrade, unlink/relink, and
  uninstall tests pass on supported Windows 11 Pro;
- the manual first-time customer checklist passes; and
- the evidence report is attached to the release decision.

The manual checklist is `certification/manual-customer-experience.md`.
The required sandbox-to-production rollout checklist is
`certification/SANDBOX_ROLLOUT_CHECKLIST.md`.
The enforceable rollout record and production publisher gate are documented in
`certification/ROLLOUT_AUTOMATION.md`.
The required first-time customer gateway is
`certification/E2E_CUSTOMER_EXPERIENCE_CHECKLIST.md`; its `e2e-verify` report must be
attached to the `manual-customer` rollout gate.

## Required external staging resources

The remaining external certification resources are:

- an email test mode, allowlisted recipient, and captured test inbox;
- a Windows 11 Pro VM or self-hosted GitHub runner with snapshot/reset capability; and
- protected GitHub `staging` environment secrets and approval rules.

Production data and provider credentials must never be reachable from a staging run.

## Desktop sandbox isolation profile

The installed Windows service reads an optional certification-only override from
`C:\ProgramData\POSPrinterEmulator\external-services.json`.

Install and validate the repository's sandbox profile before desktop E2E:

```powershell
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- desktop-profile-install
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- desktop-profile-verify
Restart-Service ReceiptLab
```

The profile covers telemetry, account linking, entitlement synchronization, device
unlinking, promotional trials, support requests, purchase and renewal links, the
Customer Portal, documentation, and support links. When the profile is
`Certification`, `Sandbox`, or `Staging`, application startup fails before making
an external request if any endpoint is a production hostname or is outside the
explicit sandbox allowlist. URLs returned by sandbox services receive the same
validation before the desktop application exposes them.

After certification, remove the override and restart the service:

```powershell
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- desktop-profile-remove --confirm REMOVE-CERTIFICATION-PROFILE
Restart-Service ReceiptLab
```

The packaged `appsettings.json` remains the production profile. Never include the
ProgramData override in a public installer or production deployment.
