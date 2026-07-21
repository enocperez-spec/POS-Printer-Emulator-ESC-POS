<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/admin-website/includes/license_keys.php';
require $root . '/admin-website/includes/license_management.php';

$failures = [];
$expectSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};
$expectContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$expectThrows = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message . ' Expected an InvalidArgumentException.';
    } catch (InvalidArgumentException) {
    }
};

$expectSame(1, activation_tier_value('Pro'), 'Pro activation byte changed.');
$expectSame(2, activation_tier_value('Enterprise'), 'Enterprise activation byte changed.');
$expectSame(3, activation_tier_value('Lite'), 'Lite activation byte is not 3.');
$expectSame('Pro', activation_tier_name(1), 'Activation byte 1 must remain Pro.');
$expectSame('Enterprise', activation_tier_name(2), 'Activation byte 2 must remain Enterprise.');
$expectSame('Lite', activation_tier_name(3), 'Activation byte 3 must decode as Lite.');
$expectThrows(static fn(): string => activation_tier_name(4), 'Unknown activation bytes must be rejected.');

foreach (['Lite', 'Pro', 'Enterprise'] as $tier) {
    $expectSame($tier, canonical_paid_tier($tier), "{$tier} must be accepted by License Manager.");
}
$expectThrows(static fn(): string => canonical_paid_tier('Trial'), 'Trial must not be accepted as an issued paid tier.');
$expectSame('2028-08-15 00:00:00',calculate_maintenance_renewal_expiration('2027-08-15 00:00:00','2027-07-20 00:00:00'),'Early renewal must add one year to current coverage.');
$expectSame('2028-07-20 00:00:00',calculate_maintenance_renewal_expiration('2027-01-01 00:00:00','2027-07-20 00:00:00'),'Lapsed renewal must start at captured-payment time.');
$expectSame('active',maintenance_status(['control_state'=>'Enabled','maintenance_expires_at'=>'2030-01-01 00:00:00','maintenance_revoked_at'=>null],new DateTimeImmutable('2029-01-01',new DateTimeZone('UTC'))),'Covered maintenance should be active.');
$expectSame('expired',maintenance_status(['control_state'=>'Enabled','maintenance_expires_at'=>'2028-01-01 00:00:00','maintenance_revoked_at'=>null],new DateTimeImmutable('2029-01-01',new DateTimeZone('UTC'))),'Elapsed maintenance should be expired.');
$expectSame('revoked',maintenance_status(['control_state'=>'Enabled','maintenance_expires_at'=>'2030-01-01 00:00:00','maintenance_revoked_at'=>'2028-01-01 00:00:00'],new DateTimeImmutable('2029-01-01',new DateTimeZone('UTC'))),'Revoked maintenance must not be active.');
$expectSame(
    '3edccffc4c9e391af25c7d5c7b612cc192b2fb3872dac8588d83eb6ad075a47d',
    maintenance_registration_digest("  José\tCafé  ",' José@Example.COM '),
    'José Café registration digest must use stable ASCII-only case folding.'
);
$expectSame('e0c8551396be02bc6377ac3d893048aa',bin2hex(registration_hash("  José\tCafé  ")),'José Café activation hash must match the cross-runtime vector.');
$expectSame('b0a53cf19e34d05b57bced7365c6b00d',bin2hex(registration_email_hash(' José@Example.COM ')),'José email activation hash must match the cross-runtime vector.');
$pastExpiration=normalize_maintenance_expiration('2025-01-01 00:00:00',1784505600,false);
$expectSame('2025-01-01 00:00:00',$pastExpiration->format('Y-m-d H:i:s'),'A replacement activation key must preserve expired maintenance coverage.');
$expectThrows(static fn():DateTimeImmutable=>normalize_maintenance_expiration('2025-01-01 00:00:00',1784505600,true),'A PPEM1 maintenance token must reject an already expired period.');

$licensesPage = file_get_contents($root . '/admin-website/licenses.php') ?: '';
$pricingPage = file_get_contents($root . '/admin-website/pricing.php') ?: '';
$setupPage = file_get_contents($root . '/admin-website/setup.php') ?: '';
$dashboardPage = file_get_contents($root . '/admin-website/index.php') ?: '';
$dashboardStyles = file_get_contents($root . '/admin-website/assets/admin-overrides.css') ?: '';
$expectContains('<option value="Lite">Lite</option>', $licensesPage, 'License Manager is missing the Lite issuance/upgrade option.');
$expectContains("foreach(['Lite','Pro','Enterprise']", $pricingPage, 'Admin Pricing is not rendering all three paid offers.');
$expectContains("value=\"maintenance\"",$pricingPage,'Admin Pricing is missing server-controlled maintenance renewal prices.');
$expectContains('extend_maintenance',$licensesPage,'License Manager is missing manual maintenance extension controls.');
$expectContains('revoke_maintenance',$licensesPage,'License Manager is missing maintenance revocation controls.');
$expectContains('data-prepare-action="restore_maintenance"',$licensesPage,'License Manager is missing confirmed maintenance restoration controls.');
$managementCode=file_get_contents($root.'/admin-website/includes/license_management.php')?:'';
$expectContains("if (!empty(\$license['maintenance_revoked_at']))",$managementCode,'Paid renewal must not bypass an Admin maintenance revocation.');
$expectContains('Restore maintenance before changing this license level.',$managementCode,'Tier replacement must not silently clear an Admin maintenance revocation.');
$expectContains('Restore maintenance before extending its coverage period.',$managementCode,'Manual extension must not silently clear an Admin maintenance revocation.');
$expectContains("'COVERAGE_TRANSFERRED'",$managementCode,'A tier replacement must record transferred coverage instead of claiming a new included year.');
$expectContains("'idempotent'=>true",$managementCode,'A repeated renewal application must return its existing expiration idempotently.');
$expectContains("ALTER TABLE installations ADD COLUMN maintenance_status",$managementCode,'License Manager schema assurance must migrate installation maintenance status.');
$expectContains("ALTER TABLE installations ADD COLUMN maintenance_expires_at",$managementCode,'License Manager schema assurance must migrate installation maintenance expiration.');
$expectContains("require __DIR__ . '/includes/license_management.php';",$dashboardPage,'Admin dashboard must load shared license-management schema assurance.');
$expectContains('ensure_license_management_schema($pdo);',$dashboardPage,'Admin dashboard must assure maintenance columns before querying them.');
$expectContains("ENUM('Trial', 'Pro', 'Enterprise', 'Lite')", $setupPage, 'Admin setup is missing the append-only installation ENUM migration.');
$expectContains("ENUM('Pro', 'Enterprise', 'Lite')", $setupPage, 'Admin setup is missing the append-only issued-license ENUM migration.');
$expectContains('.status.lite', $dashboardStyles, 'Admin dashboard is missing readable Lite status styling.');
$expectContains('.status.pro', $dashboardStyles, 'Admin dashboard is missing readable Pro status styling.');
$expectContains('.status.enterprise', $dashboardStyles, 'Admin dashboard is missing readable Enterprise status styling.');

$schema = file_get_contents($root . '/database/schema.sql') ?: '';
$migration = file_get_contents($root . '/database/migrate-lite-license-tier.sql') ?: '';
$maintenanceMigration=file_get_contents($root.'/database/migrate-annual-maintenance.sql')?:'';
$devSupport = file_get_contents($root . '/admin-website/dev-support.php') ?: '';
$expectContains("license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite')", $schema, 'Fresh database schema is missing appended Lite installation mode.');
$expectContains("license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro'", $schema, 'Fresh database schema must preserve paid-tier ordering and Pro as the legacy default.');
$expectContains("MODIFY license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro'", $migration, 'Lite migration must append Lite while preserving existing paid-tier ordering and the Pro default.');
$expectContains("maintenance_expires_at DATETIME(6)",$schema,'Fresh database schema is missing maintenance expiration persistence.');
$expectContains('license_maintenance_events',$schema,'Fresh database schema is missing maintenance history.');
$expectContains('UNIQUE KEY uq_license_maintenance_source (license_id, source_reference)',$schema,'Renewal source references must be unique per license for idempotency.');
$expectContains("'2027-07-19 23:59:59.000000'",$maintenanceMigration,'Existing paid licenses are not grandfathered through July 19, 2027.');
$expectContains("('v0.3.26', 'v0.3.26', 'Release', 'Annual Application Maintenance and Support', 'Released'", $devSupport, 'Admin Dev Support does not mark v0.3.26 maintenance as released.');
$publicRelease = json_decode(file_get_contents($root . '/website/release.json') ?: '{}', true);
$publicVersion = is_array($publicRelease) ? (string)($publicRelease['currentVersion'] ?? '') : '';
if ($publicVersion === '') {
    $failures[] = 'The public release manifest does not identify a current version.';
} else {
    $expectContains(
        "('v{$publicVersion}', 'v{$publicVersion}', 'Release'",
        $devSupport,
        "Admin Dev Support is missing the public v{$publicVersion} release."
    );
    if (!preg_match("/\\('v" . preg_quote($publicVersion, '/') . "',\\s*'v" . preg_quote($publicVersion, '/') . "',\\s*'Release',\\s*'[^']+',\\s*'Released'/", $devSupport)) {
        $failures[] = "Admin Dev Support does not mark public v{$publicVersion} as released.";
    }
}
$expectContains("('BUG-013', 'Support diagnostics failed when Stored Logos directory was absent'",$devSupport,'Admin Dev Support is missing released BUG-013.');
$expectContains("('v0.3.33', 'v0.3.33', 'Release', 'Enhanced support package and connection diagnostics', 'Released'", $devSupport, 'Enhanced support diagnostics was not marked released for v0.3.33.');
$expectContains("('v0.3.34', 'v0.3.34', 'Release', 'Encrypted backup, EULA, and support policy', 'Released'", $devSupport, 'Encrypted backup and EULA release was not marked released for v0.3.34.');
$expectContains("('v0.3.35', 'v0.3.35', 'Release', 'Receipt comparison and automated validation', 'Next'", $devSupport, 'Receipt comparison was not marked next for v0.3.35.');
$expectContains("('v0.3.36', 'v0.3.36', 'Release', 'Guided update installation and restart', 'Planned'", $devSupport, 'Guided update installation was not moved to v0.3.36.');
$expectContains("('v0.3.33', 'v0.3.33', 'Release', 'Enhanced support package and connection diagnostics', 'Released'", $schema, 'Fresh database schema is missing released v0.3.33.');
$expectContains("('v0.3.34', 'v0.3.34', 'Release', 'Encrypted backup, EULA, and support policy', 'Released'", $schema, 'Fresh database schema is missing released v0.3.34.');
$expectContains("('v0.3.35', 'v0.3.35', 'Release', 'Receipt comparison and automated validation', 'Next'", $schema, 'Fresh database schema is missing next v0.3.35.');
$expectContains("('v0.3.36', 'v0.3.36', 'Release', 'Guided update installation and restart', 'Planned'", $schema, 'Fresh database schema is missing pending v0.3.36.');
$expectContains("'pending-release-renumber-v0.3.33'", $devSupport, 'Admin Dev Support is missing the pending-release renumber migration.');
$expectContains("WHEN 'v0.3.27' THEN 'v0.3.33'", $devSupport, 'Bug targets are not migrated from v0.3.27 to v0.3.33.');
$expectContains("WHEN 'v0.3.33' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/20'", $devSupport, 'Admin Dev Support is missing the v0.3.33 diagnostics issue link.');
$expectContains("WHEN 'v0.3.34' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.34'", $devSupport, 'Admin Dev Support is missing the v0.3.34 release link.');
$expectContains("WHEN 'v0.3.35' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/21'", $devSupport, 'Admin Dev Support is missing the v0.3.35 comparison issue link.');
$expectContains("WHEN 'v0.3.36' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/3'", $devSupport, 'Admin Dev Support is missing the v0.3.36 guided update issue link.');

$entitlementEndpoint=file_get_contents($root.'/admin-website/api/maintenance-entitlement.php')?:'';
$expectContains('ensure_license_management_schema($pdo);',$entitlementEndpoint,'Maintenance entitlement API must assure license-management columns before querying them.');
$expectContains("'status'=>'not_found'",$entitlementEndpoint,'Entitlement refresh contract is missing privacy-safe not_found status.');
$expectContains("'maintenanceToken'",$entitlementEndpoint,'Entitlement refresh contract is missing signed maintenance tokens.');
if(str_contains($entitlementEndpoint,"body['activationKey']")||str_contains($entitlementEndpoint,"body['activation_key']")){
    $failures[]='Entitlement refresh must never accept an activation key.';
}

if ($failures !== []) {
    fwrite(STDERR, "Admin/database commerce tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin/database commerce tests passed.\n";
