<?php
declare(strict_types=1);

$tests = [
    __DIR__ . '/buy-commerce-tests.php',
    __DIR__ . '/admin-commerce-tests.php',
    __DIR__ . '/main-website-contract-tests.php',
    __DIR__ . '/geography-tests.php',
];
$failed = false;
foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    passthru($command, $exitCode);
    $failed = $failed || $exitCode !== 0;
}

exit($failed ? 1 : 0);
