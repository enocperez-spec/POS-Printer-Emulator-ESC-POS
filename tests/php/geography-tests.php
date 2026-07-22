<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/website/api/_geography.php';

$failures = [];
$expectSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};

$expectSame('US', normalize_country_code(' us '), 'Country codes must be normalized.');
$expectSame('ZZ', normalize_country_code('USA'), 'Invalid country codes must become unknown.');
$expectSame('GA', normalize_region_code(' ga ', 'US'), 'U.S. state codes must be normalized.');
$expectSame('', normalize_region_code('ON', 'CA'), 'Non-U.S. subdivisions must not be retained.');

$_SERVER['REMOTE_ADDR'] = '192.168.1.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
$expectSame(null, request_public_ip(), 'Private direct addresses must not trigger an external lookup.');
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
$expectSame('8.8.8.8', request_public_ip(), 'A public direct address should be eligible for coarse lookup.');

$expectSame(true, should_refresh_geography('ZZ', null), 'Missing geography must be resolved.');
$expectSame(false, should_refresh_geography('US', gmdate('Y-m-d H:i:s')), 'Fresh known geography must not be refreshed.');
$expectSame(true, should_refresh_geography('ZZ', gmdate('Y-m-d H:i:s', time() - 90000)), 'Unknown geography should retry after a day.');

if ($failures !== []) {
    fwrite(STDERR, "Geography tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Geography tests passed.\n";
