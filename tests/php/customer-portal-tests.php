<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path) ?: '';
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $needle, string $haystack, string $message) use ($expect): void {
    $expect(str_contains($haystack, $needle), $message);
};
$notContains = static function (string $needle, string $haystack, string $message) use ($expect): void {
    $expect(!str_contains($haystack, $needle), $message);
};

$auth = $read('customer-portal/includes/auth.php');
$authPage = $read('customer-portal/index.php');
$authScript = $read('customer-portal/assets/portal-auth.js');
$portalScript = $read('customer-portal/assets/portal.js');
$mailer = $read('customer-portal/includes/mailer.php');
$bootstrap = $read('customer-portal/includes/bootstrap.php');
$portal = $read('customer-portal/portal.php');
$logout = $read('customer-portal/logout.php');
$data = $read('customer-portal/includes/portal-data.php');
$schema = $read('database/schema.sql');
$migration = $read('database/migrate-customer-portal-v0.3.43.sql');
$backend = $read('admin-website/api/v1/portal-support.php');
$migrationEndpoint = $read('admin-website/api/v1/migrate-customer-portal.php');
$schemaHelper = $read('admin-website/includes/customer_portal_schema.php');
$commerceMigration = $read('database/migrate-self-service-commerce-v0.3.44.sql');
$commerceMigrationEndpoint = $read('admin-website/api/v1/migrate-self-service-commerce.php');
$commerceSchemaHelper = $read('admin-website/includes/self_service_commerce_schema.php');
$commerceBackend = $read('admin-website/api/v1/portal-commerce.php');
$promotionBackend = $read('admin-website/api/v1/portal-promotion.php');
$desktopPromotionBackend = $read('admin-website/api/v1/desktop-promotion.php');
$accessDiagnostics = $read('admin-website/api/v1/portal-access-diagnostics.php');
$portalOrderCreate = $read('buy-website/api/create-portal-order.php');
$portalOrderCapture = $read('buy-website/api/capture-portal-order.php');
$portalCheckout = $read('buy-website/self-service.php');
$configExample = $read('customer-portal/private/config.example.php');
$telemetry = $read('website/api/v1/telemetry.php');
$mainWebsite = $read('website/index.html');
$publisher = $read('tools/POSPrinterEmulator.WebsitePublisher/Program.cs');

$contains("session_set_cookie_params", $bootstrap, 'Portal sessions must configure protected cookies.');
$contains("'secure' => true", $bootstrap, 'Portal session cookie must be Secure.');
$contains("'httponly' => true", $bootstrap, 'Portal session cookie must be HttpOnly.');
$contains("'samesite' => 'Strict'", $bootstrap, 'Portal session cookie must use SameSite Strict.');
$contains("Content-Security-Policy", $bootstrap, 'Portal must send a Content Security Policy.');
$contains("Cache-Control: no-store", $bootstrap, 'Portal pages must not be cached.');
$contains("SHOW DATABASES", $bootstrap, 'Portal must discover the single legacy database when its configured name is blank.');
$contains("count(\$available) !== 1", $bootstrap, 'Portal must reject ambiguous database discovery.');
$contains("portal_require_csrf", $portal, 'Portal mutations must enforce CSRF.');
$contains("portal_require_csrf", $logout, 'Sign out must enforce CSRF.');
$contains("PASSWORD_ARGON2ID", $auth, 'Portal passwords must use Argon2id.');
$contains("session_regenerate_id(true)", $auth, 'Portal sessions must rotate identifiers.');
$contains("failed_login_count", $auth, 'Portal login must track account failures.');
$contains("portal_rate_limit", $auth, 'Portal authentication workflows must be rate limited.');
$contains("If a matching", $read('customer-portal/index.php'), 'Enrollment and recovery must use generic responses.');
$contains('data-auth-mode="reset"', $authPage, 'Password recovery links must activate the reset panel.');
$contains('data-auth-mode="verify"', $authPage, 'Enrollment links must activate the verification panel.');
$contains("history.replaceState", $authScript, 'Authentication tabs must expose the active form in the URL.');
$contains("portal_kick_communication_worker", $mailer, 'Security email requests must wake the protected communication worker.');
$contains("admin.posprinteremulator.com", $mailer, 'The portal must restrict worker handoff to the trusted Admin host.');
$contains("count(\$matches) !== 1", $auth, 'Ambiguous email matches must not expose or merge customer identities.');
$contains("c.display_name", $auth, 'Enrollment email parameters must include the customer display name.');
$contains("UNHEX(SHA2(:token,256))", $auth, 'Verification and reset tokens must be stored and queried by digest.');
$contains("portal_encrypt_secret", $portal, 'MFA secrets must be encrypted before storage.');
$contains("portal_recovery_codes", $portal, 'MFA must include hashed recovery codes.');
$contains("otpauth://totp/", $portal, 'MFA enrollment must create a standard authenticator provisioning URI.');
$contains("data-mfa-qr", $portal, 'MFA enrollment must provide a QR-code target.');
$contains("new window.QRCode", $portalScript, 'MFA enrollment must generate the authenticator QR code locally.');
$expect(is_file($root . '/customer-portal/assets/vendor/qrcodejs/qrcode.min.js'), 'The local QR-code library must be packaged with the portal.');
$contains("filemtime(__DIR__ . '/assets/portal.js')", $portal, 'Portal assets must use automatic cache-busting after updates.');
$contains("current-license", $portal, 'The owned license card must receive a distinct visual state.');
$contains("portal_customer_display_name", $portal, 'The Overview greeting must use the customer full-name helper.');
$contains("Maintenance and Support Until:", $portal, 'The Overview must show the complete maintenance and support label.');
$contains("Renew Maintenance and Support", $portal, 'The Overview must link customers to maintenance renewal.');
$contains("maintenance-renewal", $portal, 'Maintenance reminders must link to the renewal section.');
$contains("customer_id=:customer_id", $data, 'Portal data queries must enforce canonical ownership.');
$contains("customer_id=:customer_id", $portal, 'Portal actions must enforce canonical ownership.');
$contains("portal_recently_reauthenticated", $portal, 'Sensitive actions must require recent reauthentication.');
$contains("INTERVAL 24 HOUR", $portal, 'Device deactivation must enforce a cooldown.');
$contains("support-handoff|", $portal, 'Pending support handoffs must be safely retryable and rate limited.');
$contains("activation_key_ending", $data, 'Portal must use only a masked activation-key ending.');
$contains("'consentHistory'", $data, 'Portal account export and preferences must include auditable consent history.');
$contains("Five-Day Promotional Trial", $portal, 'Portal must clearly explain the one-time promotional trial.');
$contains("there is no key to copy or paste", $portal, 'Portal must direct customers to the automatic in-app promotional trial.');
$contains("portal_start_promotion_backend", $portal, 'Portal must issue promotions through the protected backend.');
$contains("portal_recently_reauthenticated", $portal, 'Portal promotion issuance must require recent password confirmation.');
$contains("portal_recently_reauthenticated", $portal, 'Portal commerce must require recent password confirmation.');
$contains("UNHEX(SHA2(:token,256))", $portal, 'Portal checkout tokens must be persisted only as digests.');
$contains("DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 MINUTE)", $portal, 'Portal checkout sessions must expire quickly.');
$contains("portal_checkout_intents", $commerceMigration, 'Commerce migration must contain canonical checkout intents.');
$contains("portal_promotion_claims", $commerceMigration, 'Commerce migration must contain one-time promotion identity claims.');
$contains("portal_promotion_exceptions", $commerceMigration, 'Commerce migration must require explicit admin promotion exceptions.');
$contains("GET_LOCK('ppe_self_service_commerce_v1'", $commerceSchemaHelper, 'Commerce schema upgrades must be serialized.');
$contains("'self-service-commerce-v0.3.44'", $commerceMigrationEndpoint, 'Commerce migration must record durable evidence.');
$contains("require_commerce_service_token", $commerceBackend, 'Commerce fulfillment must authenticate the Buy service.');
$contains("state='Captured'", $commerceBackend, 'Verified payment capture must be recorded before entitlement fulfillment.');
$contains("apply_paid_maintenance_renewal", $commerceBackend, 'Self-service maintenance must reuse idempotent renewal fulfillment.');
$contains("promotion_require_service_token", $promotionBackend, 'Promotion issuance must authenticate the portal service.');
$contains("portal_promotion_claims", $promotionBackend, 'Promotion issuance must enforce one-time identity claims.');
$contains("portal_promotion_exceptions", $promotionBackend, 'Repeat promotions must require an unused Admin exception.');
$contains("issue_promotion_token", $promotionBackend, 'Promotion issuance must return a signed entitlement.');
$contains("X_INSTALLATION_TOKEN", strtoupper($desktopPromotionBackend), 'Desktop promotion issuance must authenticate the registered installation.');
$contains("portal_promotion_claims", $desktopPromotionBackend, 'Desktop promotion issuance must enforce permanent one-use claims.');
$contains("email_verified_at", $desktopPromotionBackend, 'Desktop promotion issuance must require a verified customer.');
$contains("protect_promotion_token", $desktopPromotionBackend, 'Desktop promotion retries must store the entitlement encrypted.');
$contains("Desktop Application", $desktopPromotionBackend, 'Desktop promotion issuance must create an auditable server record.');
$contains("portal_commerce_service_request", $portalOrderCreate, 'Buy order creation must resolve the opaque portal session server to server.');
$contains("self_service_offer", $portalOrderCreate, 'Buy order creation must calculate prices on the server.');
$contains("portal_commerce_service_request", $portalOrderCapture, 'Buy capture must fulfill through the canonical Admin service.');
$contains("server records", $portalCheckout, 'Checkout must explain that identity and pricing come from protected server records.');
$notContains("paypal.secret", $portal, 'The Customer Portal must not contain PayPal secrets.');
$notContains("activation_key,", $data, 'Portal data queries must not retrieve complete activation keys.');
$contains("portal_deactivated_at", $telemetry, 'Deactivated installations must stop updating server activity.');
$contains("portal_accounts", $schema, 'Fresh schema must contain portal accounts.');
$contains("communication_service_authorized", $accessDiagnostics, 'Portal access diagnostics must require the protected service token.');
$contains("UNHEX(SHA2(:email,256))", $accessDiagnostics, 'Portal access diagnostics must look up customer identity by normalized email digest.');
$contains("portal_password_resets", $accessDiagnostics, 'Portal access diagnostics must include password-reset status.');
$contains("communication_outbox", $accessDiagnostics, 'Portal access diagnostics must include security-email delivery status.');
$notContains("password_hash", $accessDiagnostics, 'Portal access diagnostics must never retrieve password hashes.');
$contains("portal_sessions", $migration, 'Portal migration must contain revocable sessions.');
$contains("portal_support_replies", $migration, 'Portal migration must contain private support replies.');
$contains("portal_device_actions", $migration, 'Portal migration must contain audited device actions.');
$contains("ensure_customer_portal_schema", $schemaHelper, 'Admin deployment must provide an idempotent Customer Portal schema upgrade.');
$contains("GET_LOCK('ppe_customer_portal_schema_v1'", $schemaHelper, 'Customer Portal schema upgrades must be serialized.');
$contains("crm_authorization_header", $migrationEndpoint, 'Customer Portal migration must authenticate a server bearer token.');
$contains("'secure-customer-portal-v0.3.43'", $migrationEndpoint, 'Customer Portal migration must record durable evidence.');
$contains("crm_authorization_header", $backend, 'Admin support handoff must authenticate a server bearer token.');
$contains("subject, contact information", $backend, 'Public GitHub issues must keep private support content in the Admin Portal.');
$notContains("\$request['subject']", $backend, 'Public GitHub issue titles must not expose customer-provided subjects.');
$notContains('github_token', $configExample, 'Customer Portal configuration must not contain a GitHub token.');
$notContains('activation_key', $configExample, 'Customer Portal configuration must not contain activation keys.');
$expect(!preg_match('/(?:password|token|secret|encryption_key)\s*=>\s*[\'"][A-Za-z0-9+\/_-]{24,}/i', $configExample), 'Example configuration appears to contain a real secret.');
$contains('"configure-customer-portal"', $publisher, 'Publisher must support protected Customer Portal configuration.');
$contains("ProtectedData.Protect", $publisher, 'Publisher must protect the persistent portal encryption key locally.');
$contains("migrate-customer-portal", $publisher, 'Publisher must support an authenticated Customer Portal migration.');
$contains("'promotion_backend_url' => 'https://admin.posprinteremulator.com/api/v1/portal-promotion.php'", $publisher, 'Publisher must configure the protected promotion endpoint.');
$contains("'communications_worker_url' => 'https://admin.posprinteremulator.com/api/v1/communications-worker.php?max=5'", $publisher, 'Publisher must configure the protected communication-worker handoff.');
$contains("https://userportal.posprinteremulator.com/", $mainWebsite, 'The main website must link customers to the Customer Portal.');

$portalFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/customer-portal',
        FilesystemIterator::SKIP_DOTS
    )
);
foreach ($portalFiles as $phpFile) {
    if (!$phpFile->isFile() || strtolower($phpFile->getExtension()) !== 'php') {
        continue;
    }
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($phpFile->getPathname()), $output, $code);
    $expect($code === 0, $phpFile->getFilename() . ' failed PHP syntax validation.');
}

require_once $root . '/customer-portal/includes/auth.php';
require_once $root . '/customer-portal/includes/portal-data.php';
$expect(portal_password_is_valid('Correct-Horse-9'), 'A compliant portal password should be accepted.');
$expect(!portal_password_is_valid('short9A'), 'A short portal password should be rejected.');
$testSecretBytes = random_bytes(20);
$testSecret = portal_base32_encode($testSecretBytes);
$expect(hash_equals($testSecretBytes, portal_base32_decode($testSecret)), 'TOTP Base32 encoding must round-trip.');
$testCounter = intdiv(time(), 30);
$expect(portal_verify_totp($testSecret, portal_totp($testSecret, $testCounter)), 'A current TOTP code should verify.');
$expect(portal_customer_display_name('  Enoc   Perez ') === 'Enoc Perez', 'Overview must preserve and normalize the full customer name.');
$expect(portal_customer_display_name('') === 'Customer', 'Overview must gracefully fall back when the customer name is unavailable.');
$expect(portal_license_status_label('Enabled') === 'Active', 'Enabled licenses must display as Active.');
$reminderBeforeWindow = portal_maintenance_reminder('2026-10-23', new DateTimeImmutable('2026-07-22', new DateTimeZone('UTC')));
$expect($reminderBeforeWindow['state'] === 'current', 'Maintenance reminder must remain hidden before the three-month window.');
$reminderInWindow = portal_maintenance_reminder('2026-10-23', new DateTimeImmutable('2026-07-23', new DateTimeZone('UTC')));
$expect($reminderInWindow['state'] === 'expiring', 'Maintenance reminder must begin exactly three calendar months before expiration.');
$expect($reminderInWindow['daysRemaining'] === 92, 'Maintenance reminder must calculate calendar days remaining.');
$reminderExpired = portal_maintenance_reminder('2026-10-23', new DateTimeImmutable('2026-10-24', new DateTimeZone('UTC')));
$expect($reminderExpired['state'] === 'expired', 'Maintenance reminder must switch to expired after the coverage date.');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Customer Portal tests passed.\n";
