<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
function private_config(): array
{
    return ['data_protection' => ['activation_key_key' => base64_encode(str_repeat('K', 32))]];
}
require $root . '/admin-website/includes/customer_crm.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$contains = static function (string $needle, string $haystack, string $message) use ($expect): void {
    $expect(str_contains($haystack, $needle), $message);
};

$expect(crm_normalize_email(' Customer@Example.COM ') === 'customer@example.com', 'Email normalization changed unexpectedly.');
$expect(crm_normalize_email('not-an-email') === '', 'Invalid email must not enter the canonical customer record.');
$expect(crm_mask_email('customer@example.com') === 'c•••••••@example.com', 'Admin list email masking is incorrect.');
$expect(crm_activation_key_ending('PPE1-ABCD-1234') === '1234', 'Only the final four activation-key characters should be exposed.');
if (function_exists('openssl_encrypt')) {
    $protectedKey = protect_activation_key('PPE1-TEST-ACTIVATION');
    $expect($protectedKey['plaintext'] === '' && is_string($protectedKey['ciphertext']), 'Configured production storage must not retain activation-key plaintext.');
    $expect(reveal_activation_key(['activation_key'=>'','activation_key_ciphertext'=>$protectedKey['ciphertext'],'activation_key_nonce'=>$protectedKey['nonce'],'activation_key_tag'=>$protectedKey['tag']]) === 'PPE1-TEST-ACTIVATION', 'Protected activation keys must decrypt and authenticate with the deployment key.');
} else {
    try {
        protect_activation_key('PPE1-TEST-ACTIVATION');
        $expect(false, 'A configured encryption key must fail closed when OpenSSL is unavailable.');
    } catch (RuntimeException) {
    }
}
$uuid = crm_uuid();
$expect((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid), 'Customer IDs must be random RFC 4122 version 4 UUIDs.');

$crm = file_get_contents($root . '/admin-website/includes/customer_crm.php') ?: '';
$page = file_get_contents($root . '/admin-website/customers.php') ?: '';
$licensesPage = file_get_contents($root . '/admin-website/licenses.php') ?: '';
$api = file_get_contents($root . '/admin-website/api/v1/customers.php') ?: '';
$migrationApi = file_get_contents($root . '/admin-website/api/v1/migrate-customer-crm.php') ?: '';
$auth = file_get_contents($root . '/admin-website/includes/auth.php') ?: '';
$schema = file_get_contents($root . '/database/schema.sql') ?: '';
$telemetry = file_get_contents($root . '/website/api/v1/telemetry.php') ?: '';
$supportApi = file_get_contents($root . '/admin-website/api/support-request.php') ?: '';

$contains('Deliberately create one record per unverified installation', $crm, 'Unverified matching emails must not be automatically merged.');
$contains('activation_key_fingerprint BINARY(32)', $crm, 'Activation keys need a fingerprint for privacy-safe lookup.');
$contains('activation_key_ciphertext', $crm, 'Legacy recoverable activation keys need encrypted storage.');
$contains('customer_merge_history', $crm, 'Reviewed merges need permanent history.');
$contains('customer_consents', $crm, 'Consent evidence must be append-only.');
$contains('customer_email_suppressions', $crm, 'Email suppression must be represented before Brevo integration.');
$contains("'customers.export'", $auth, 'Customer exports require a dedicated Admin capability.');
$contains("require_admin_capability('customers.export')", $page, 'Customer export does not enforce its dedicated capability.');
$contains("Content-Disposition: attachment", $page, 'Customer CSV export is unavailable.');
$contains('name="maintenance"', $page, 'Customer search is missing the maintenance filter.');
$contains('name="activity"', $page, 'Customer search is missing the activity filter.');
$contains('name="marketing"', $page, 'Customer search is missing the marketing-consent filter.');
$contains('name="support"', $page, 'Customer search is missing the support-history filter.');
$contains('name="version"', $page, 'Customer search is missing the application-version filter.');
$contains('activation_key_ending', $page, 'Customer detail does not use masked activation-key endings.');
$expect(!str_contains($page, "['activation_key']"), 'The customer page must not render full activation keys.');
$expect(!str_contains($licensesPage, 'l.activation_key,'), 'Routine License Manager rows must not query complete activation keys.');
$expect(!str_contains($licensesPage, 'data-key="<?= e((string)$license[\'activation_key\'])'), 'Routine License Manager rows must not embed complete activation keys.');
$contains("hash_equals('MERGE'", $page, 'Customer merge requires an explicit confirmation phrase.');
$contains("'customer_purchases'", $page, 'Reviewed customer merges must preserve linked purchase ownership.');
$contains("hash_equals('VERIFIED'", $page, 'Manual email verification requires explicit confirmation.');
$contains("^Bearer\\s+", $api, 'The CRM service API must require bearer authentication.');
$contains("hash('sha256', \$providedToken)", $api, 'The service API must compare a token digest, not a plaintext configured token.');
$contains('customer_api_rate_limits', $api, 'The CRM service API must enforce persistent rate limits.');
$contains('crm_authorization_header()', $api, 'The CRM service API must support the host-provided bearer-header handoff.');
$expect(!str_contains($api, 'activation_key,'), 'The CRM API must never query full activation keys.');
$contains("WHERE c.customer_id=:id", $api, 'The CRM API must use exact-ID lookup instead of customer enumeration.');
$contains("hash('sha256', \$match[1])", $migrationApi, 'The CRM migration operation must authenticate with the protected service-token digest.');
$contains('backfill_customer_crm($pdo)', $migrationApi, 'The protected CRM migration operation must run the idempotent ownership backfill.');
$expect(!str_contains($migrationApi, 'serviceToken'), 'The server migration endpoint must never contain or return the plaintext service token.');
$contains('customer_id CHAR(36)', $schema, 'Fresh databases are missing canonical customer identifiers.');
$contains('customer_admin_audit', $schema, 'Fresh databases are missing customer audit evidence.');
$contains('link_telemetry_customer', $telemetry, 'New authenticated installations must link into the customer foundation automatically.');
$contains('Email equality is never ownership proof', $telemetry, 'Telemetry linking must preserve the unverified-email identity boundary.');
$contains("['42S02','42S22']", $telemetry, 'Telemetry must remain available during a staged additive CRM deployment.');
$contains('backfill_customer_crm($pdo);', $supportApi, 'Authenticated support requests must resolve canonical customer ownership.');
$contains('(reference_code,customer_id,license_id', $supportApi, 'New support requests must persist their customer relationship.');

if ($failures !== []) {
    fwrite(STDERR, "Customer CRM tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Customer CRM tests passed.\n";
