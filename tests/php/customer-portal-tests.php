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
$contains("portal_require_csrf", $portal, 'Portal mutations must enforce CSRF.');
$contains("portal_require_csrf", $logout, 'Sign out must enforce CSRF.');
$contains("PASSWORD_ARGON2ID", $auth, 'Portal passwords must use Argon2id.');
$contains("session_regenerate_id(true)", $auth, 'Portal sessions must rotate identifiers.');
$contains("failed_login_count", $auth, 'Portal login must track account failures.');
$contains("portal_rate_limit", $auth, 'Portal authentication workflows must be rate limited.');
$contains("If a matching", $read('customer-portal/index.php'), 'Enrollment and recovery must use generic responses.');
$contains("count(\$matches) !== 1", $auth, 'Ambiguous email matches must not expose or merge customer identities.');
$contains("UNHEX(SHA2(:token,256))", $auth, 'Verification and reset tokens must be stored and queried by digest.');
$contains("portal_encrypt_secret", $portal, 'MFA secrets must be encrypted before storage.');
$contains("portal_recovery_codes", $portal, 'MFA must include hashed recovery codes.');
$contains("customer_id=:customer_id", $data, 'Portal data queries must enforce canonical ownership.');
$contains("customer_id=:customer_id", $portal, 'Portal actions must enforce canonical ownership.');
$contains("portal_recently_reauthenticated", $portal, 'Sensitive actions must require recent reauthentication.');
$contains("INTERVAL 24 HOUR", $portal, 'Device deactivation must enforce a cooldown.');
$contains("support-handoff|", $portal, 'Pending support handoffs must be safely retryable and rate limited.');
$contains("activation_key_ending", $data, 'Portal must use only a masked activation-key ending.');
$contains("'consentHistory'", $data, 'Portal account export and preferences must include auditable consent history.');
$contains("Five-day paid-edition promotion", $portal, 'Portal must clearly explain the one-time promotional trial.');
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
$contains("portal_commerce_service_request", $portalOrderCreate, 'Buy order creation must resolve the opaque portal session server to server.');
$contains("self_service_offer", $portalOrderCreate, 'Buy order creation must calculate prices on the server.');
$contains("portal_commerce_service_request", $portalOrderCapture, 'Buy capture must fulfill through the canonical Admin service.');
$contains("server records", $portalCheckout, 'Checkout must explain that identity and pricing come from protected server records.');
$notContains("paypal.secret", $portal, 'The Customer Portal must not contain PayPal secrets.');
$notContains("activation_key,", $data, 'Portal data queries must not retrieve complete activation keys.');
$contains("portal_deactivated_at", $telemetry, 'Deactivated installations must stop updating server activity.');
$contains("portal_accounts", $schema, 'Fresh schema must contain portal accounts.');
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
$expect(portal_password_is_valid('Correct-Horse-9'), 'A compliant portal password should be accepted.');
$expect(!portal_password_is_valid('short9A'), 'A short portal password should be rejected.');
$testSecretBytes = random_bytes(20);
$testSecret = portal_base32_encode($testSecretBytes);
$expect(hash_equals($testSecretBytes, portal_base32_decode($testSecret)), 'TOTP Base32 encoding must round-trip.');
$testCounter = intdiv(time(), 30);
$expect(portal_verify_totp($testSecret, portal_totp($testSecret, $testCounter)), 'A current TOTP code should verify.');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Customer Portal tests passed.\n";
