<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$telemetry = file_get_contents($root . '/website/api/v1/telemetry.php') ?: '';
$geography = file_get_contents($root . '/website/api/_geography.php') ?: '';
$download = file_get_contents($root . '/website/download.php') ?: '';
$privacy = file_get_contents($root . '/website/privacy.html') ?: '';
$schema = file_get_contents($root . '/database/schema.sql') ?: '';
$legacyAdmin = file_get_contents($root . '/website/admin/index.php') ?: '';
$failures = [];

$expectContains = static function (string $needle, string $message) use ($telemetry, &$failures): void {
    if (!str_contains($telemetry, $needle)) {
        $failures[] = $message;
    }
};

$paidModes = "['Lite', 'Pro', 'Enterprise']";
$expectContains(
    "in_array(\$reportedMode, {$paidModes}, true)",
    'Telemetry must preserve a client-reported Lite license instead of downgrading it to Trial.'
);
$expectContains("\$body['maintenanceStatus']??'NotApplicable'",'Telemetry must accept the optional maintenanceStatus field while preserving older clients.');
$expectContains("\$body['maintenanceExpiresAt']",'Telemetry must accept the optional maintenanceExpiresAt field.');
$expectContains("['NotApplicable','Active','Expired','Revoked']",'Telemetry must accept the exact four-value maintenance status contract.');
$expectContains('maintenance_status = :maintenance_status','Telemetry must persist maintenance status without activation keys.');
if(str_contains($telemetry,'activationKey')||str_contains($telemetry,'maintenanceToken')){
    $failures[]='Telemetry must not collect activation keys or maintenance tokens.';
}
$expectContains(
    "in_array(\$managed['license_tier'], {$paidModes}, true)",
    'Telemetry must preserve a managed Lite license instead of replacing it with the reported fallback.'
);
$setup=file_get_contents($root.'/website/api/setup.php')?:'';
if(!str_contains($setup,'maintenance_expires_at')||!str_contains($setup,'maintenance_revoked_at')){
    $failures[]='Main website setup must migrate issued-license maintenance columns before telemetry reads them.';
}

foreach (['country_code', 'region_code', 'geo_updated_at'] as $field) {
    if (!str_contains($telemetry, $field) || !str_contains($schema, $field)) {
        $failures[] = "Coarse geography field {$field} is missing from telemetry or schema.";
    }
}
if (!str_contains($geography, "\$_SERVER['REMOTE_ADDR']") || str_contains($geography, 'HTTP_X_FORWARDED_FOR')) {
    $failures[] = 'Geography must use the direct request address and must not trust a client-supplied forwarded address.';
}
if (preg_match('/\b(ip_address|remote_addr)\b/i', $schema) === 1) {
    $failures[] = 'The product analytics schema must not persist raw IP addresses.';
}
foreach (['download_events_daily', 'is_probable_download_bot', "header('Location: downloads/POSPrinterEmulatorSetup-"] as $needle) {
    if (!str_contains($download, $needle)) {
        $failures[] = "Download tracking contract is missing {$needle}.";
    }
}
if (!str_contains($privacy, 'processed transiently') || !str_contains($privacy, 'does not store the public IP address')) {
    $failures[] = 'The privacy notice must clearly disclose transient IP geolocation and non-retention.';
}
if (!str_contains($privacy, 'Download totals record download starts')) {
    $failures[] = 'The privacy notice must distinguish download starts from completed downloads or installs.';
}

if (!str_contains($legacyAdmin, "https://admin.posprinteremulator.com/") ||
    str_contains($legacyAdmin, 'require ') ||
    str_contains($legacyAdmin, 'database()')) {
    $failures[] = 'The retired website admin endpoint must remain a redirect-only route to the Admin Portal.';
}

if ($failures !== []) {
    fwrite(STDERR, "Main website contract tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Main website contract tests passed.\n";
