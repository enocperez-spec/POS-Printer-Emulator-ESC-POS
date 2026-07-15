<?php
declare(strict_types=1);

function issue_activation_key(string $customerName, string $emailAddress): array
{
    $customerName = trim(preg_replace('/\s+/', ' ', $customerName) ?? '');
    $emailAddress = strtolower(trim($emailAddress));
    if ($customerName === '' || strlen($customerName) > 160) {
        throw new InvalidArgumentException('Customer or company name is required.');
    }
    if (strlen($emailAddress) > 254 || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }

    $privateKeyPath = dirname(__DIR__) . '/private/vendor-private-key.pem';
    $privateKeyPem = file_get_contents($privateKeyPath);
    if ($privateKeyPem === false) {
        throw new RuntimeException('The protected signing key is unavailable.');
    }
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if ($privateKey === false) {
        throw new RuntimeException('The protected signing key could not be loaded.');
    }

    $guidBytes = random_bytes(16);
    $timestamp = time();
    $payload = chr(1)
        . $guidBytes
        . pack('N2', 0, $timestamp)
        . registration_hash($customerName)
        . registration_hash($emailAddress);
    if (strlen($payload) !== 57) {
        throw new RuntimeException('The license payload has an unexpected length.');
    }

    $derSignature = '';
    if (!openssl_sign($payload, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The activation key could not be signed.');
    }
    $signature = der_signature_to_p1363($derSignature);
    $activationKey = 'PPE1-' . rtrim(strtr(base64_encode($payload . $signature), '+/', '-_'), '=');

    return [
        'license_id' => dotnet_guid_string($guidBytes),
        'issued_at' => gmdate('Y-m-d H:i:s', $timestamp),
        'customer_name' => $customerName,
        'email_address' => $emailAddress,
        'activation_key' => $activationKey,
    ];
}

function registration_hash(string $value): string
{
    $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    return substr(hash('sha256', $normalized, true), 0, 16);
}

function der_signature_to_p1363(string $der): string
{
    $offset = 0;
    if (ord($der[$offset++] ?? "\0") !== 0x30) {
        throw new RuntimeException('The signature sequence is invalid.');
    }
    read_der_length($der, $offset);
    $r = read_der_integer($der, $offset);
    $s = read_der_integer($der, $offset);
    return normalize_signature_integer($r) . normalize_signature_integer($s);
}

function read_der_length(string $der, int &$offset): int
{
    $first = ord($der[$offset++] ?? "\0");
    if (($first & 0x80) === 0) {
        return $first;
    }
    $count = $first & 0x7f;
    if ($count < 1 || $count > 2) {
        throw new RuntimeException('The signature length is invalid.');
    }
    $length = 0;
    for ($index = 0; $index < $count; $index++) {
        $length = ($length << 8) | ord($der[$offset++] ?? "\0");
    }
    return $length;
}

function read_der_integer(string $der, int &$offset): string
{
    if (ord($der[$offset++] ?? "\0") !== 0x02) {
        throw new RuntimeException('The signature integer is invalid.');
    }
    $length = read_der_length($der, $offset);
    $value = substr($der, $offset, $length);
    $offset += $length;
    return $value;
}

function normalize_signature_integer(string $value): string
{
    $value = ltrim($value, "\0");
    if (strlen($value) > 32) {
        throw new RuntimeException('The signature integer is too large.');
    }
    return str_pad($value, 32, "\0", STR_PAD_LEFT);
}

function dotnet_guid_string(string $bytes): string
{
    $hex = bin2hex($bytes);
    return substr($hex, 6, 2) . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2) . '-'
        . substr($hex, 10, 2) . substr($hex, 8, 2) . '-'
        . substr($hex, 14, 2) . substr($hex, 12, 2) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}
