<?php
declare(strict_types=1);

function activation_tier_value(string $licenseTier): int
{
    return match ($licenseTier) {
        'Pro' => 1,
        'Enterprise' => 2,
        'Lite' => 3,
        default => throw new InvalidArgumentException('Choose a valid license level.'),
    };
}

function activation_tier_name(int $tierValue): string
{
    return match ($tierValue) {
        1 => 'Pro',
        2 => 'Enterprise',
        3 => 'Lite',
        default => throw new InvalidArgumentException('The imported activation key level is invalid.'),
    };
}

function issue_activation_key(string $customerName, string $emailAddress, string $licenseTier = 'Pro'): array
{
    $customerName = trim(preg_replace('/\s+/', ' ', $customerName) ?? '');
    $emailAddress = strtolower(trim($emailAddress));
    if ($customerName === '' || strlen($customerName) > 160) {
        throw new InvalidArgumentException('Customer or company name is required.');
    }
    if (strlen($emailAddress) > 254 || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    $tierValue = activation_tier_value($licenseTier);

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
    $payload = chr(2)
        . $guidBytes
        . pack('N2', 0, $timestamp)
        . registration_hash($customerName)
        . registration_hash($emailAddress)
        . chr($tierValue);
    if (strlen($payload) !== 58) {
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
        'license_tier' => $licenseTier,
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

function validate_activation_key_record(array $license): void
{
    $activationKey = trim((string)($license['activation_key'] ?? ''));
    if (!str_starts_with($activationKey, 'PPE1-')) {
        throw new InvalidArgumentException('The imported activation key format is invalid.');
    }
    $encoded = substr($activationKey, 5);
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    if ($decoded === false || !in_array(strlen($decoded), [121, 122], true)) {
        throw new InvalidArgumentException('The imported activation key is incomplete or damaged.');
    }
    $version = ord($decoded[0]);
    $payloadLength = match ($version) {
        1 => 57,
        2 => 58,
        default => 0,
    };
    if ($payloadLength === 0 || strlen($decoded) !== $payloadLength + 64) {
        throw new InvalidArgumentException('The imported activation key version is unsupported.');
    }
    $payload = substr($decoded, 0, $payloadLength);
    $signature = substr($decoded, $payloadLength, 64);

    $licenseId = dotnet_guid_string(substr($payload, 1, 16));
    $tier = $version === 1
        ? 'Pro'
        : activation_tier_name(ord($payload[57]));
    if (!hash_equals($licenseId, canonical_license_uuid((string)($license['license_id'] ?? ''))) ||
        !hash_equals($tier, canonical_paid_tier((string)($license['license_tier'] ?? ''))) ||
        !hash_equals(substr($payload, 25, 16), registration_hash((string)($license['customer_name'] ?? ''))) ||
        !hash_equals(substr($payload, 41, 16), registration_hash((string)($license['email_address'] ?? '')))) {
        throw new InvalidArgumentException('The imported activation key does not match its customer, license ID, or level.');
    }

    $privateKeyPath = dirname(__DIR__) . '/private/vendor-private-key.pem';
    $privateKeyPem = file_get_contents($privateKeyPath);
    $privateKey = $privateKeyPem === false ? false : openssl_pkey_get_private($privateKeyPem);
    $details = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
    $publicKey = is_array($details) && isset($details['key']) ? openssl_pkey_get_public((string)$details['key']) : false;
    if ($publicKey === false || openssl_verify($payload, p1363_signature_to_der($signature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new InvalidArgumentException('The imported activation key signature is invalid.');
    }
}

function p1363_signature_to_der(string $signature): string
{
    if (strlen($signature) !== 64) {
        throw new InvalidArgumentException('The activation-key signature length is invalid.');
    }
    $encodeInteger = static function (string $value): string {
        $value = ltrim($value, "\0");
        $value = $value === '' ? "\0" : $value;
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0" . $value;
        }
        return "\x02" . chr(strlen($value)) . $value;
    };
    $body = $encodeInteger(substr($signature, 0, 32)) . $encodeInteger(substr($signature, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
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
