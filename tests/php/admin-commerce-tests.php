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

$licensesPage = file_get_contents($root . '/admin-website/licenses.php') ?: '';
$pricingPage = file_get_contents($root . '/admin-website/pricing.php') ?: '';
$setupPage = file_get_contents($root . '/admin-website/setup.php') ?: '';
$dashboardStyles = file_get_contents($root . '/admin-website/assets/admin-overrides.css') ?: '';
$expectContains('<option value="Lite">Lite</option>', $licensesPage, 'License Manager is missing the Lite issuance/upgrade option.');
$expectContains("foreach(['Lite','Pro','Enterprise']", $pricingPage, 'Admin Pricing is not rendering all three paid offers.');
$expectContains("ENUM('Trial', 'Pro', 'Enterprise', 'Lite')", $setupPage, 'Admin setup is missing the append-only installation ENUM migration.');
$expectContains("ENUM('Pro', 'Enterprise', 'Lite')", $setupPage, 'Admin setup is missing the append-only issued-license ENUM migration.');
$expectContains('.status.lite', $dashboardStyles, 'Admin dashboard is missing readable Lite status styling.');
$expectContains('.status.pro', $dashboardStyles, 'Admin dashboard is missing readable Pro status styling.');
$expectContains('.status.enterprise', $dashboardStyles, 'Admin dashboard is missing readable Enterprise status styling.');

$schema = file_get_contents($root . '/database/schema.sql') ?: '';
$migration = file_get_contents($root . '/database/migrate-lite-license-tier.sql') ?: '';
$devSupport = file_get_contents($root . '/admin-website/dev-support.php') ?: '';
$expectContains("license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite')", $schema, 'Fresh database schema is missing appended Lite installation mode.');
$expectContains("license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro'", $schema, 'Fresh database schema must preserve paid-tier ordering and Pro as the legacy default.');
$expectContains("MODIFY license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro'", $migration, 'Lite migration must append Lite while preserving existing paid-tier ordering and the Pro default.');
$expectContains("'Four-tier licensing and upgrade paths', 'Released'", $devSupport, 'Admin Dev Support is not tracking v0.3.25 as Released.');
$expectContains("('v0.3.26', 'v0.3.26', 'Release', 'Receipt comparison and automated validation', 'Next'", $devSupport, 'Receipt comparison was not moved to v0.3.26.');

if ($failures !== []) {
    fwrite(STDERR, "Admin/database commerce tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin/database commerce tests passed.\n";
