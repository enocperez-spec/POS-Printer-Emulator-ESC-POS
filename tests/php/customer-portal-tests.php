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
$communications = $read('admin-website/includes/communications.php');
$bootstrap = $read('customer-portal/includes/bootstrap.php');
$portal = $read('customer-portal/portal.php');
$receipt = $read('customer-portal/receipt.php');
$invoice = $read('customer-portal/invoice.php');
$logout = $read('customer-portal/logout.php');
$heartbeat = $read('customer-portal/session-heartbeat.php');
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
$activationDeliveryBackend = $read('admin-website/api/v1/portal-license-delivery.php');
$deviceEntitlementBackend = $read('admin-website/api/v1/device-entitlement.php');
$deviceUnlinkBackend = $read('admin-website/api/v1/device-unlink.php');
$promotionBackend = $read('admin-website/api/v1/portal-promotion.php');
$desktopPromotionBackend = $read('admin-website/api/v1/desktop-promotion.php');
$accessDiagnostics = $read('admin-website/api/v1/portal-access-diagnostics.php');
$portalOrderCreate = $read('buy-website/api/create-portal-order.php');
$portalOrderCapture = $read('buy-website/api/capture-portal-order.php');
$portalCheckout = $read('buy-website/self-service.php');
$configExample = $read('customer-portal/private/config.example.php');
$telemetry = $read('website/api/v1/telemetry.php');
$accountLinkApi = $read('website/api/v1/account-link.php');
$accountLinkMigration = $read('database/migrate-account-based-activation.sql');
$desktopProgram = $read('src/ReceiptEmulator.App/Program.cs');
$desktopAccountLink = $read('src/ReceiptEmulator.App/AccountLinkService.cs');
$viewer = $read('src/ReceiptEmulator.Viewer/src/App.tsx');
$mainWebsite = $read('website/index.html');
$publisher = $read('tools/POSPrinterEmulator.WebsitePublisher/Program.cs');
$releaseManifest = $read('website/release.json');
$releaseSynchronizer = $read('tools/ReceiptLab.Build/Program.cs');

$contains("session_set_cookie_params", $bootstrap, 'Portal sessions must configure protected cookies.');
$contains("'secure' => true", $bootstrap, 'Portal session cookie must be Secure.');
$contains("'httponly' => true", $bootstrap, 'Portal session cookie must be HttpOnly.');
$contains("'samesite' => 'Strict'", $bootstrap, 'Portal session cookie must use SameSite Strict.');
$contains("Content-Security-Policy", $bootstrap, 'Portal must send a Content Security Policy.');
$contains("\$portalFormActions .= ' ' . \$portalBuyBaseUrl", $bootstrap, 'Portal CSP must permit the configured Buy origin across the checkout redirect.');
$contains("preg_match('#^https://[A-Za-z0-9.-]+\$#', \$portalBuyBaseUrl)", $bootstrap, 'Portal CSP must validate the configured Buy origin before allowing it.');
$notContains("form-action *", $bootstrap, 'Portal CSP must not allow unrestricted form destinations.');
$contains("Cache-Control: no-store", $bootstrap, 'Portal pages must not be cached.');
$contains("X-PPE-Correlation-ID", $bootstrap, 'Portal journeys must expose a correlation identifier.');
$contains("journey_correlation_id", $auth, 'Registration and recovery records must retain journey correlation.');
$contains("journey_correlation_id", $mailer, 'Portal email records must retain journey correlation.');
$contains("journey_correlation_id", $portal, 'Portal checkout and device actions must retain journey correlation.');
$contains("'correlationId' => \$linkId", $accountLinkApi, 'Computer linking must return its journey correlation identifier.');
$contains("X-PPE-Correlation-ID", $desktopAccountLink, 'The desktop link client must propagate journey correlation.');
$contains("custom_id", $portalOrderCreate, 'PayPal order creation must carry journey correlation in custom_id.');
$contains("hash_equals(\$correlationId, \$providerCorrelationId)", $portalOrderCapture, 'Payment capture must reject correlation mismatches.');
$contains("SHOW DATABASES", $bootstrap, 'Portal must discover the single legacy database when its configured name is blank.');
$contains("count(\$available) !== 1", $bootstrap, 'Portal must reject ambiguous database discovery.');
$contains("portal_require_csrf", $portal, 'Portal mutations must enforce CSRF.');
$contains("portal_require_csrf", $logout, 'Sign out must enforce CSRF.');
$contains("PASSWORD_ARGON2ID", $auth, 'Portal passwords must use Argon2id.');
$contains("session_regenerate_id(true)", $auth, 'Portal sessions must rotate identifiers.');
$contains("PORTAL_IDLE_TIMEOUT_SECONDS = 600", $auth, 'Portal sessions must expire after 10 minutes of inactivity.');
$notContains("time() - 1800", $auth, 'The previous 30-minute inactivity timeout must not remain active.');
$contains("portal_current_account()", $heartbeat, 'The activity heartbeat must validate the current account before extending a session.');
$contains("portal_require_csrf", $heartbeat, 'The activity heartbeat must enforce CSRF.');
$contains("data-session-logout-form", $portal, 'The portal must expose a secure logout form to the inactivity controller.');
$contains("pointermove", $portalScript, 'Mouse movement must count as Customer Portal activity.');
$contains("session-heartbeat.php", $portalScript, 'Recent browser activity must synchronize with the server-side session.');
$contains("reason.value = 'idle'", $portalScript, 'Automatic sign-out must identify inactivity as the reason.');
$contains("session=expired", $logout, 'Idle logout must return the customer to the login page with an expiration notice.');
$contains("10 minutes of inactivity", $authPage, 'The login page must explain an automatic inactivity logout.');
$contains("failed_login_count", $auth, 'Portal login must track account failures.');
$contains("portal_rate_limit", $auth, 'Portal authentication workflows must be rate limited.');
$contains("If a matching", $read('customer-portal/index.php'), 'Enrollment and recovery must use generic responses.');
$contains('data-auth-mode="reset"', $authPage, 'Password recovery links must activate the reset panel.');
$contains('data-auth-mode="verify"', $authPage, 'Enrollment links must activate the verification panel.');
$contains("history.replaceState", $authScript, 'Authentication tabs must expose the active form in the URL.');
$contains("portal_kick_communication_worker", $mailer, 'Security email requests must wake the protected communication worker.');
$contains("admin(?:-sandbox)?", $mailer, 'Worker handoff must allow only the exact production or sandbox Admin host.');
$contains('workerHost !== $supportBackendHost', $mailer, 'Worker handoff must match the configured protected Admin backend.');
$contains("userportal-sandbox.posprinteremulator.com", $mailer, 'Sandbox verification links must pass the exact-host email allowlist.');
$contains("communication_sync_brevo_template_activation", $communications, 'Admin template activation must synchronize the Brevo provider state.');
$contains("count(\$matches) !== 1", $auth, 'Ambiguous email matches must not expose or merge customer identities.');
$contains("c.display_name", $auth, 'Enrollment email parameters must include the customer display name.');
$contains("UNHEX(SHA2(:token,256))", $auth, 'Verification and reset tokens must be stored and queried by digest.');
$contains("portal_encrypt_secret", $portal, 'MFA secrets must be encrypted before storage.');
$contains("portal_recovery_codes", $portal, 'MFA must include hashed recovery codes.');
$contains("otpauth://totp/", $portal, 'MFA enrollment must create a standard authenticator provisioning URI.');
$contains("data-mfa-qr", $portal, 'MFA enrollment must provide a QR-code target.');
$contains("new window.QRCode", $portalScript, 'MFA enrollment must generate the authenticator QR code locally.');
$contains("portal_disable_mfa", $portal, 'Customers need a protected self-service MFA disable action.');
$contains("portal_verify_account_second_factor", $auth, 'MFA removal must require an authenticator or recovery code.');
$contains("confirmation_phrase", $portal, 'MFA removal must require an explicit confirmation phrase.');
$contains("data-confirm=", $portal, 'MFA removal must display a final browser warning.');
$contains("mfa_reenrollment_required", $portal, 'Administrator recovery must force MFA enrollment before normal portal use.');
$contains("session_revision=session_revision+1", $auth, 'MFA removal must invalidate every existing Customer Portal session.');
$contains("mfa_secret_ciphertext=NULL", $auth, 'MFA removal must clear encrypted enrollment material.');
$contains("'MFA Disabled'", $mailer, 'Customer MFA removal must map to a security notification template.');
$contains("portal_security_template_ready", $portal, 'Customer MFA removal must fail closed unless its security notification is approved.');
$expect(is_file($root . '/customer-portal/assets/vendor/qrcodejs/qrcode.min.js'), 'The local QR-code library must be packaged with the portal.');
$contains("filemtime(__DIR__ . '/assets/portal.js')", $portal, 'Portal assets must use automatic cache-busting after updates.');
$contains("current-license", $portal, 'The owned license card must receive a distinct visual state.');
$contains("portal_customer_display_name", $portal, 'The Overview greeting must use the customer full-name helper.');
$contains("'billing'", $portal, 'The Customer Portal navigation must include Purchase and Billing.');
$contains("Purchase &amp; Billing", $portal, 'The billing page must clearly identify its transaction-history purpose.');
$contains("portal_return", $auth, 'Authentication must preserve a safe Customer Portal billing return destination.');
$contains("'billing' => '/portal.php?page=billing'", $auth, 'Billing-return authentication must resolve to the account-owned Purchase and Billing page.');
$contains("'plans' => '/portal.php?page=plans'", $auth, 'Purchase-option authentication must resolve to the account-owned Plans page.');
$contains("'computers' => '/portal.php?page=computers'", $auth, 'Computer linking must return verified customers to the Computers page.');
$contains("portal_return_page", $auth, 'Authentication must validate Customer Portal return destinations against an allowlist.');
$contains("in_array(\$portalReturn, ['plans', 'computers'], true)", $auth, 'Guests entering through purchase options or computer linking must be able to create a verified account.');
$contains("portal_return", $authPage, 'The sign-in entry point must accept and preserve the billing return request.');
$contains("Sign in or create an account to compare Lite, Pro, and Enterprise licenses", $authPage, 'The purchase-option entry point must clearly explain the protected account flow.');
$contains("'?return=' . rawurlencode(\$portalReturn)", $authPage, 'The protected return intent must survive account enrollment.');
$contains("'/index.php?return=' . rawurlencode(\$portalReturn)", $read('customer-portal/verify.php'), 'Email verification must return new customers to the protected account flow.');
$contains("portal_purchase_type_label", $portal, 'Purchase history must distinguish licenses, upgrades, and maintenance renewals.');
$contains("portal_purchase_license_label", $portal, 'Purchase history must associate transactions with masked licenses.');
$contains("portal_purchase_status_label", $portal, 'Purchase history must display normalized payment status.');
$contains("/receipt.php?reference=", $portal, 'Each purchase must provide an authenticated receipt action.');
$notContains("/invoice.php?reference=", $portal, 'Billing must provide one clear receipt action instead of a duplicate invoice action.');
$contains("portal_purchase_record", $receipt, 'Receipt downloads must resolve purchases through canonical ownership.');
$contains("portal_require_account", $receipt, 'Receipt downloads must require an authenticated Customer Portal account.');
$contains("portal_purchase_invoice_number", $receipt, 'Receipts must display the stable invoice number.');
$contains("portal_purchase_payment_approval_reference", $receipt, 'Receipts must display the verified PayPal capture reference.');
$contains("Content-Disposition: attachment", $receipt, 'Receipts must support a direct download.');
$contains("Payment and account credentials are intentionally excluded", $receipt, 'Receipts must explain their sensitive-data boundary.');
$notContains("paypal.secret", $receipt, 'Receipt rendering must never contain PayPal credentials.');
$notContains("activation_key,", $receipt, 'Receipt rendering must never retrieve complete activation keys.');
$contains("portal_purchase_record", $invoice, 'Invoice downloads must resolve purchases through canonical ownership.');
$contains("portal_require_account", $invoice, 'Invoice downloads must require an authenticated Customer Portal account.');
$contains("portal_purchase_payment_approval_reference", $invoice, 'Invoices must display the verified PayPal capture reference.');
$contains("PayPal order reference", $invoice, 'Invoices must distinguish the PayPal order from its capture reference.');
$contains("Content-Disposition: attachment", $invoice, 'Invoices must support a direct download.');
$contains("Invoice from EPCOM Ltd.", $invoice, 'Invoices must identify EPCOM Ltd. as the seller.');
$contains("Payment and account credentials are intentionally excluded", $invoice, 'Invoices must explain their sensitive-data boundary.');
$notContains("paypal.secret", $invoice, 'Invoice rendering must never contain PayPal credentials.');
$notContains("activation_key,", $invoice, 'Invoice rendering must never retrieve complete activation keys.');
$contains("i.customer_id=p.customer_id", $data, 'Billing joins must preserve canonical customer ownership.');
$contains("COALESCE(i.replacement_license_id,i.license_id)", $data, 'Billing history must associate upgrades and renewals with the correct license.');
$contains("Maintenance and Support Until:", $portal, 'The Overview must show the complete maintenance and support label.');
$contains("Renew Maintenance and Support", $portal, 'The Overview must link customers to maintenance renewal.');
$contains("maintenance-renewal", $portal, 'Maintenance reminders must link to the renewal section.');
$contains("New version available!", $portal, 'The portal must notify customers when a newer release is available.');
$contains("Download Latest Version", $portal, 'Eligible customers must receive a clearly labeled latest-version action.');
$contains("Your software is up to date.", $portal, 'Current installations must receive an up-to-date confirmation.');
$contains("View release notes", $portal, 'Version notifications must link to release notes.');
$contains("releaseDate", $releaseManifest, 'The public release manifest must provide a release date.');
$contains("releaseNotesUrl", $releaseManifest, 'The public release manifest must provide release notes.');
$contains("downloadUrl", $releaseManifest, 'The public release manifest must provide the current installer URL.');
$contains("releaseNotesUrl =", $releaseSynchronizer, 'Release synchronization must update portal release-note metadata automatically.');
$contains("downloadUrl =", $releaseSynchronizer, 'Release synchronization must update the portal installer URL automatically.');
$contains("customer_id=:customer_id", $data, 'Portal data queries must enforce canonical ownership.');
$contains("customer_id=:customer_id", $portal, 'Portal actions must enforce canonical ownership.');
$contains("portal_recently_reauthenticated", $portal, 'Sensitive actions must require recent reauthentication.');
$contains("INTERVAL 24 HOUR", $portal, 'Device deactivation must enforce a cooldown.');
$contains("approve-computer-link", $portal, 'The Customer Portal must provide an explicit computer-link approval action.');
$contains("portal_recently_reauthenticated", $portal, 'Computer-link approval must require recent password confirmation.');
$contains(
    "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/",
    $portal,
    'Computer-link approval must accept every canonical license identifier issued by the licensing system.'
);
$notContains(
    "[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}",
    $portal,
    'Computer-link approval must not reject canonical license identifiers that do not carry RFC UUID version bits.'
);
$contains("license_device_bindings", $portal, 'Computer activation and deactivation must update authoritative license-device bindings.');
$notContains("ACTIVATION_KEY_CLAIMED", $portal, 'The portal must not retain a backup-key claim workflow.');
$contains("binding_state=\\'Active\\'", $portal, 'The portal must enforce active device assignments.');
$contains("request_token_hash", $accountLinkApi, 'The public account-link API must store only a digest of the request token.');
$contains("ACCOUNT_LINK_LIFETIME_MINUTES = 10", $accountLinkApi, 'Computer-link codes must expire after ten minutes.');
$contains("INTERVAL 1 HOUR", $accountLinkApi, 'Computer-link creation must be rate limited per authenticated installation.');
$contains("X_INSTALLATION_TOKEN", $accountLinkApi, 'Computer-link requests must authenticate the registered installation.');
$contains("status='Consumed'", $accountLinkApi, 'Computer-link requests must be single-use after activation.');
$notContains("activation_key_fingerprint", $accountLinkApi, 'Computer linking must not accept or resolve activation-key fingerprints.');
$contains("license_device_bindings", $accountLinkMigration, 'The account-activation migration must create license-device bindings.');
$contains("portal_computer_link_requests", $schemaHelper, 'The protected schema helper must deploy computer-link storage.');
$notContains('"/api/license/activate"', $desktopProgram, 'The local activation-key API must be removed.');
$contains("X-Installation-Token", $desktopAccountLink, 'The desktop link service must authenticate with installation credentials.');
$contains("Link This Computer", $viewer, 'The desktop License page must expose account-based computer linking.');
$notContains("activation key", strtolower($viewer), 'The desktop License page must not expose activation-key instructions.');
$contains("support-handoff|", $portal, 'Pending support handoffs must be safely retryable and rate limited.');
$notContains("activation_key_ending", $data, 'Portal data must not retrieve legacy activation-key endings.');
$contains("'consentHistory'", $data, 'Portal account export and preferences must include auditable consent history.');
$contains("Five-Day Promotional Trial", $portal, 'Portal must clearly explain the one-time promotional trial.');
$contains("there is no key to copy or paste", $portal, 'Portal must direct customers to the automatic in-app promotional trial.');
$contains("portal_start_promotion_backend", $portal, 'Portal must issue promotions through the protected backend.');
$contains("portal_recently_reauthenticated", $portal, 'Portal promotion issuance must require recent password confirmation.');
$contains("portal_recently_reauthenticated", $portal, 'Portal commerce must require recent password confirmation.');
$notContains("resend-activation", $portal, 'The Customer Portal must not expose a credential-resend action.');
$contains("http_response_code(410)", $activationDeliveryBackend, 'The retired credential-delivery endpoint must return HTTP 410.');
$contains("ACCOUNT_LICENSING_REQUIRED", $activationDeliveryBackend, 'The retired credential-delivery endpoint must direct clients to account licensing.');
$contains("X_INSTALLATION_TOKEN", $deviceEntitlementBackend, 'Device entitlement synchronization must authenticate the registered installation.');
$contains("email_verified_at IS NOT NULL", $deviceEntitlementBackend, 'Only a verified customer account may receive a device entitlement.');
$contains(
    "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/",
    $deviceEntitlementBackend,
    'Device entitlement synchronization must accept every canonical installation identifier issued by the application.'
);
$notContains(
    "[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}",
    $deviceEntitlementBackend,
    'Device entitlement synchronization must not require RFC UUID version bits.'
);
$contains(
    "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/",
    $deviceUnlinkBackend,
    'Device unlinking must accept every canonical installation identifier issued by the application.'
);
$contains("issue_device_entitlement", $deviceEntitlementBackend, 'The protected backend must issue a signed, device-bound entitlement.');
$contains("entitlement_revision", $deviceEntitlementBackend, 'Device entitlement responses must include the current revision.');
$notContains("activationKey']", $portal, 'The browser-facing portal must never retrieve an activation key.');
$contains("['MAINTENANCE', 'UPGRADE', 'LICENSE']", $portal, 'Portal commerce must allow a distinct new-license purchase.');
$contains("Buy Additional", $portal, 'Owned customers must receive an explicitly labeled additional-license option.');
$contains("portal_purchase_tier", $auth, 'Authentication must validate and preserve a selected purchase tier.');
$contains("portal_post_auth_destination", $auth, 'Authentication must return customers to their selected purchase.');
$contains("Portal Purchase Account Requested", $auth, 'New purchasers must be able to create a verified Customer Portal account.');
$contains("ENUM('MAINTENANCE','UPGRADE','LICENSE')", $commerceSchemaHelper, 'Checkout storage must distinguish new licenses from upgrades.');
$contains("insert_issued_license", $commerceBackend, 'A paid new-license checkout must issue a separate canonical license.');
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
$contains("mfa_reenrollment_required", $schema, 'Fresh schema must support mandatory MFA re-enrollment after recovery.');
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
$contains("'v0.3.53','UPE-127'", $migrationEndpoint, 'Account-link migration must mark the current release and enhancement complete.');
$notContains("item_key='v0.3.43'", $migrationEndpoint, 'A later Customer Portal migration must not reopen the released v0.3.43 roadmap item.');
$contains("crm_authorization_header", $backend, 'Admin support handoff must authenticate a server bearer token.');
$contains("subject, contact information", $backend, 'Public GitHub issues must keep private support content in the Admin Portal.');
$notContains("\$request['subject']", $backend, 'Public GitHub issue titles must not expose customer-provided subjects.');
$notContains('github_token', $configExample, 'Customer Portal configuration must not contain a GitHub token.');
$notContains('activation_key', $configExample, 'Customer Portal configuration must not contain activation keys.');
$expect(!preg_match('/(?:password|token|secret|encryption_key)\s*=>\s*[\'"][A-Za-z0-9+\/_-]{24,}/i', $configExample), 'Example configuration appears to contain a real secret.');
$contains('"configure-customer-portal"', $publisher, 'Publisher must support protected Customer Portal configuration.');
$contains("ProtectedData.Protect", $publisher, 'Publisher must protect the persistent portal encryption key locally.');
$contains("migrate-customer-portal", $publisher, 'Publisher must support an authenticated Customer Portal migration.');
$contains('adminBaseUrl + "/api/v1/portal-promotion.php"', $publisher, 'Publisher must configure the protected promotion endpoint from the deployment-specific Admin URL.');
$contains('adminBaseUrl + "/api/v1/communications-worker.php?max=5"', $publisher, 'Publisher must configure the protected communication-worker handoff from the deployment-specific Admin URL.');
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
$invoiceNumber = portal_purchase_invoice_number([
    'purchase_reference' => 'portal:00000000-0000-4000-8000-000000000001',
    'paid_at' => '2026-07-28 00:15:08',
]);
$expect(
    preg_match('/^PPE-INV-20260728-[A-F0-9]{10}$/', $invoiceNumber) === 1,
    'Portal invoice numbers must be stable, date-based, and non-secret.'
);
$outdatedVersion = portal_version_status('v0.3.33', '0.3.36');
$expect($outdatedVersion['updateAvailable'] === true, 'An older installed version must be marked for update.');
$expect($outdatedVersion['versionsBehind'] === 3, 'Version distance must follow the product release-number sequence.');
$currentVersion = portal_version_status('0.3.47.0', '0.3.47');
$expect($currentVersion['updateAvailable'] === false && $currentVersion['versionsBehind'] === 0, 'The current release must be marked up to date.');
$rolloverVersion = portal_version_status('0.3.99', '0.4.00');
$expect($rolloverVersion['versionsBehind'] === 1, 'Version distance must support the v0.3.99 to v0.4.00 rollover.');
$expect(portal_version_ordinal('0.3.100') === null, 'Versions outside the documented two-digit release sequence must be rejected.');
$selectedLicense = portal_primary_active_license([
    ['control_state' => 'Revoked', 'license_id' => 'newer-revoked'],
    ['control_state' => 'Enabled', 'license_id' => 'active-license'],
]);
$expect(($selectedLicense['license_id'] ?? '') === 'active-license', 'Update eligibility must use the active license rather than a newer revoked record.');
$selectedLicense = portal_primary_active_license([
    ['control_state' => 'Enabled', 'license_id' => 'expired-complimentary', 'license_expires_at' => '2020-01-01 00:00:00'],
    ['control_state' => 'Enabled', 'license_id' => 'permanent-license', 'license_expires_at' => null],
]);
$expect(($selectedLicense['license_id'] ?? '') === 'permanent-license', 'Expired complimentary licenses must not be eligible for activation or updates.');
$expect(
    portal_license_display_status([
        'control_state' => 'Enabled',
        'license_expires_at' => '2020-01-01 00:00:00',
    ]) === 'Expired',
    'The Customer Portal must clearly identify an elapsed complimentary entitlement.'
);
$activeMaintenance = [
    'control_state' => 'Enabled',
    'maintenance_expires_at' => '2026-10-23 23:59:59',
    'maintenance_revoked_at' => null,
];
$expect(
    portal_has_active_maintenance($activeMaintenance, new DateTimeImmutable('2026-07-23 12:00:00', new DateTimeZone('UTC'))),
    'Active unexpired maintenance must allow update downloads.'
);
$activeMaintenance['maintenance_revoked_at'] = '2026-07-23 12:01:00';
$expect(
    !portal_has_active_maintenance($activeMaintenance, new DateTimeImmutable('2026-07-23 12:00:00', new DateTimeZone('UTC'))),
    'Revoked maintenance must block update downloads.'
);
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
