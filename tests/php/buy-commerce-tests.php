<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/buy-website/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/buy-website/includes/license_keys.php';

$failures = [];
$expectSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};
$expectThrows = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message . ' Expected an InvalidArgumentException.';
    } catch (InvalidArgumentException) {
    }
};

$expectSame(['Lite', 'Pro', 'Enterprise'], paid_license_tiers(), 'Paid tier order changed.');
$expectSame('Lite', clean_license_tier(' lite '), 'Lite tier normalization failed.');
$expectSame('Pro', clean_license_tier('PRO'), 'Pro tier normalization failed.');
$expectSame('Enterprise', clean_license_tier('enterprise'), 'Enterprise tier normalization failed.');
$expectThrows(static fn(): string => clean_license_tier('Trial'), 'Trial must not be accepted as a paid checkout tier.');

$expectSame(1, activation_tier_value('Pro'), 'Pro activation byte changed.');
$expectSame(2, activation_tier_value('Enterprise'), 'Enterprise activation byte changed.');
$expectSame(3, activation_tier_value('Lite'), 'Lite activation byte is not 3.');
$expectThrows(static fn(): int => activation_tier_value('Trial'), 'Trial must not receive a paid activation byte.');

$available = ['Lite', 'Pro', 'Enterprise'];
$expectSame('Lite', select_purchase_tier($available, null), 'Lite should be the default paid offer.');
$expectSame('Pro', select_purchase_tier($available, 'pro'), 'Safe Pro query preselection failed.');
$expectSame('Enterprise', select_purchase_tier($available, 'Enterprise'), 'Safe Enterprise query preselection failed.');
$expectSame('Lite', select_purchase_tier($available, 'invalid'), 'Invalid query tiers must fall back safely.');
$expectSame('Pro', select_purchase_tier(['Pro', 'Enterprise'], 'Lite'), 'Unavailable query tiers must use the first configured fallback.');

$configuredOffers = configured_license_offers();
$expectSame('24.99', $configuredOffers['Lite']['price'] ?? null, 'Lite fallback price must be $24.99.');
$expectSame('USD', $configuredOffers['Lite']['currency'] ?? null, 'Lite fallback currency must be USD.');

if ($failures !== []) {
    fwrite(STDERR, "Buy commerce tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Buy commerce tests passed.\n";
