# Rollout automation

The rollout automation turns the sandbox checklist into a fail-closed production gate.
Rollout records are operational evidence and belong under `artifacts/rollouts/`; they
must not contain passwords, API keys, tokens, unredacted customer data, or payment
details.

## 1. Start the rollout

Commit the release candidate, build the installer and checksum, and run:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-start --version VERSION --installer artifacts/installer/POSPrinterEmulatorSetup-VERSION-win-x64.exe --actor "Release owner"
```

This creates `artifacts/rollouts/vVERSION.json` and binds it to:

- the product version;
- the current 40-character Git commit;
- the installer path and verified SHA-256; and
- all required sandbox rollout gates.

## 2. Record each gate

After completing a checklist section, attach a local evidence file/directory or HTTPS
evidence URL:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-set --record artifacts/rollouts/vVERSION.json --gate sandbox-preflight --status Passed --evidence artifacts/certification/CERTIFICATION_RUN --notes "Preflight passed" --actor "Test owner"
```

Valid gate IDs are:

```text
scope-review
sandbox-preflight
source-gate
sandbox-deployment
web-endpoints
account-security
installer-desktop
entitlements
device-linking
paypal-commerce
maintenance-support
email-communications
admin-audit
ux-accessibility-seo
failure-rollback
manual-customer
evidence-review
production-plan
```

Every gate is critical. A critical gate cannot be marked `NotApplicable`. Updating any
gate invalidates every prior approval.

## Customer-experience gateway

Complete `certification/E2E_CUSTOMER_EXPERIENCE_CHECKLIST.md` using a first-time tester
and record the 15 required journeys in a copy of
`certification/e2e-experience.example.json`.

Generate the customer-experience report:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- e2e-verify --record C:\secure\e2e-vVERSION.json --installer artifacts/installer/POSPrinterEmulatorSetup-VERSION-win-x64.exe --report-directory artifacts/certification/e2e-vVERSION
```

The E2E gateway fails unless all 15 journeys pass with evidence, the test uses a
captured allowlisted inbox and Windows 11 Pro, the result is no older than seven days,
three customer-experience approvals are current, and version/commit/installer integrity
matches.

Use the generated `artifacts/certification/e2e-vVERSION/report.json` as evidence when
passing the `manual-customer` rollout gate. Production readiness rejects a generic URL
or unrelated report for this gate.

## 3. Record independent approvals

Approvals are accepted only after all gates pass and contain evidence:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-approve --record artifacts/rollouts/vVERSION.json --role release-owner --name "Name"
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-approve --record artifacts/rollouts/vVERSION.json --role test-owner --name "Name"
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-approve --record artifacts/rollouts/vVERSION.json --role rollback-owner --name "Name"
```

Each approval is bound to the approved commit and installer SHA-256.

## 4. Generate the production-readiness report

Run from the exact approved commit with a clean working tree:

```text
dotnet run --project tools/POSPrinterEmulator.Certification --configuration Release -- rollout-verify --record artifacts/rollouts/vVERSION.json --report-directory artifacts/certification/production-readiness-vVERSION
```

Verification fails when:

- any required gate is missing, incomplete, failed, skipped, or lacks evidence;
- evidence paths do not exist or a URL does not use HTTPS;
- any of the three approvals is missing, stale, or bound to another artifact;
- sandbox evidence is older than 14 days;
- the working tree is dirty;
- the current version or commit differs from the rollout record;
- the installer or checksum differs; or
- the rollout is not explicitly `ReadyForProduction`.

## 5. Authorize the production publisher

Set the readiness report for the deployment process:

```text
PPE_ROLLOUT_READINESS_REPORT=artifacts/certification/production-readiness-vVERSION/report.json
```

On PowerShell:

```powershell
$env:PPE_ROLLOUT_READINESS_REPORT = "artifacts/certification/production-readiness-vVERSION/report.json"
```

The Website Publisher blocks production uploads, deletes, publishes, configuration
changes, schema uploads, and protected configuration changes unless the report:

- passed the rollout readiness check;
- matches the current Git commit; and
- completed within the previous 24 hours.

Sandbox publishing and the sandbox GitHub-release fetch remain independent of this
production gate.

## Event history

The rollout record retains creation, gate-update, and approval events with UTC
timestamps and actors. Never edit a rollout record manually. Use the commands so
approval invalidation and history remain consistent.
