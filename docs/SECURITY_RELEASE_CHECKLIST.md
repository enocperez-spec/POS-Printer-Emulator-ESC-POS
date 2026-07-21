# Security release checklist

Complete this checklist for every feature, bug fix, and published installer. Attach the completed checklist to the GitHub release and the Admin Portal Dev Support release record.

## Design review

- [ ] Identify trust boundaries, data flows, stored sensitive data, and external services.
- [ ] Document authentication, authorization, license-tier, and least-privilege decisions.
- [ ] Define input limits and validation for requests, uploads, imports, printer data, and paths.
- [ ] Define failure behavior that does not disclose credentials, keys, personal data, or stack traces.

## Website and Admin Portal

- [ ] HTTPS/TLS, HSTS, secure cookies, CSRF protection, and security headers verified.
- [ ] Object-level authorization, login throttling, and API rate limits tested.
- [ ] SQL uses parameterized queries; HTML output is escaped; uploads and redirects are validated.
- [ ] Secrets come from protected server configuration and are absent from source, browser code, logs, and backups.
- [ ] Audit records contain actions and identifiers, never passwords, tokens, license keys, or receipt contents.

## Desktop application

- [ ] Trial/Lite/Pro/Enterprise gates are enforced in both UI and local APIs.
- [ ] Activation data, registration data, and local history use least-privilege storage and protected permissions.
- [ ] Imported files, logos, receipt bytes, and network data have size, format, and path validation.
- [ ] Update and installer packages use HTTPS, checksum validation, trusted signatures, rollback, and safe shutdown.
- [ ] Logs redact credentials, activation keys, customer email addresses, and receipt content.

## Automated verification

- [ ] Dependency audit completed with no unresolved critical vulnerabilities.
- [ ] Secret scan completed with no newly introduced credentials.
- [ ] Security regression tests pass for authentication, authorization, rate limiting, injection, storage, licensing, and updates.
- [ ] Clean install, upgrade, repair, uninstall, and restricted-user smoke tests pass.

## Release decision

- [ ] All critical and high findings are fixed or formally blocked from release.
- [ ] Remaining lower-severity findings have an owner, target release, and documented acceptance.
- [ ] Security reviewer and release owner signed the companion sign-off record.
