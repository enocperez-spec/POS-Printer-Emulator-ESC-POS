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

function issue_activation_key(
    string $customerName,
    string $emailAddress,
    string $licenseTier = 'Pro',
    ?string $maintenanceExpiresAt = null,
    ?string $privateKeyOverride = null
): array
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

    $guidBytes = random_bytes(16);
    $timestamp = time();
    $maintenanceExpiration = normalize_maintenance_expiration($maintenanceExpiresAt, $timestamp, false);
    $payload = chr(3)
        . $guidBytes
        . pack_unix_seconds($timestamp)
        . registration_hash($customerName)
        . registration_email_hash($emailAddress)
        . chr($tierValue)
        . pack_unix_seconds($maintenanceExpiration->getTimestamp());
    if (strlen($payload) !== 66) {
        throw new RuntimeException('The license payload has an unexpected length.');
    }
    $signature = sign_license_payload($payload,$privateKeyOverride);
    $activationKey = 'PPE1-' . rtrim(strtr(base64_encode($payload . $signature), '+/', '-_'), '=');
    $licenseId = dotnet_guid_string($guidBytes);

    return [
        'license_id' => $licenseId,
        'issued_at' => gmdate('Y-m-d H:i:s', $timestamp),
        'customer_name' => $customerName,
        'email_address' => $emailAddress,
        'license_tier' => $licenseTier,
        'activation_key' => $activationKey,
        'maintenance_expires_at' => $maintenanceExpiration->format('Y-m-d H:i:s'),
        'maintenance_token' => $maintenanceExpiration->getTimestamp() > $timestamp ? issue_maintenance_token(
            $licenseId,
            $licenseTier,
            $maintenanceExpiration->format('Y-m-d H:i:s'),
            $timestamp,
            $privateKeyOverride
        ) : null,
    ];
}

function issue_maintenance_token(
    string $licenseId,
    string $licenseTier,
    string $maintenanceExpiresAt,
    ?int $issuedTimestamp = null,
    ?string $privateKeyOverride = null
): string {
    $licenseId = canonical_license_uuid($licenseId);
    $tierValue = activation_tier_value($licenseTier);
    $issuedTimestamp ??= time();
    $expiration = normalize_maintenance_expiration($maintenanceExpiresAt, $issuedTimestamp);
    $payload = chr(1)
        . dotnet_guid_bytes($licenseId)
        . pack_unix_seconds($issuedTimestamp)
        . pack_unix_seconds($expiration->getTimestamp())
        . chr($tierValue);
    if (strlen($payload) !== 34) {
        throw new RuntimeException('The maintenance payload has an unexpected length.');
    }
    return 'PPEM1-' . rtrim(strtr(base64_encode($payload . sign_license_payload($payload,$privateKeyOverride)), '+/', '-_'), '=');
}

function sign_license_payload(string $payload, ?string $privateKeyOverride = null): string
{
    $privateKeyPath = dirname(__DIR__) . '/private/vendor-private-key.pem';
    $privateKeyPem = $privateKeyOverride ?? file_get_contents($privateKeyPath);
    if ($privateKeyPem === false) {
        throw new RuntimeException('The protected signing key is unavailable.');
    }
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if ($privateKey === false) {
        throw new RuntimeException('The protected signing key could not be loaded.');
    }
    $derSignature = '';
    if (!openssl_sign($payload, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The entitlement could not be signed.');
    }
    return der_signature_to_p1363($derSignature);
}

function normalize_maintenance_expiration(?string $value, int $issuedTimestamp, bool $requireFuture = true): DateTimeImmutable
{
    $issuedAt = (new DateTimeImmutable('@' . $issuedTimestamp))->setTimezone(new DateTimeZone('UTC'));
    if ($value === null || trim($value) === '') {
        return $issuedAt->modify('+1 year');
    }
    try {
        $expiration = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new InvalidArgumentException('The maintenance expiration is invalid.');
    }
    if ($requireFuture && $expiration <= $issuedAt) {
        throw new InvalidArgumentException('The maintenance expiration must be later than the issue time.');
    }
    return $expiration;
}

function pack_unix_seconds(int $timestamp): string
{
    if ($timestamp < 0) {
        throw new InvalidArgumentException('The entitlement timestamp is invalid.');
    }
    return pack('N2', intdiv($timestamp, 4294967296), $timestamp % 4294967296);
}

function registration_hash(string $value): string
{
    $normalized = strtoupper(trim(preg_replace('/[ \t\r\n\f\v]+/', ' ', $value) ?? '', " \t\r\n\f\v"));
    return substr(hash('sha256', $normalized, true), 0, 16);
}

function registration_email_hash(string $value): string
{
    $normalized = strtolower(trim($value," \t\r\n\f\v"));
    return substr(hash('sha256',$normalized,true),0,16);
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
    if ($decoded === false || !in_array(strlen($decoded), [121, 122, 130], true)) {
        throw new InvalidArgumentException('The imported activation key is incomplete or damaged.');
    }
    $version = ord($decoded[0]);
    $payloadLength = match ($version) {
        1 => 57,
        2 => 58,
        3 => 66,
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
        !hash_equals(
            substr($payload, 41, 16),
            $version === 3
                ? registration_email_hash((string)($license['email_address'] ?? ''))
                : registration_hash((string)($license['email_address'] ?? ''))
        )) {
        throw new InvalidArgumentException('The imported activation key does not match its customer, license ID, or level.');
    }
    if ($version === 3) {
        $parts = unpack('Nhigh/Nlow', substr($payload, 58, 8));
        $expirationTimestamp = ((int)$parts['high'] * 4294967296) + (int)$parts['low'];
        $recordExpiration = trim((string)($license['maintenance_expires_at'] ?? ''));
        if ($recordExpiration === '' ||
            (new DateTimeImmutable($recordExpiration, new DateTimeZone('UTC')))->getTimestamp() !== $expirationTimestamp) {
            throw new InvalidArgumentException('The imported activation key maintenance period does not match its record.');
        }
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

function dotnet_guid_bytes(string $licenseId): string
{
    $hex = str_replace('-', '', canonical_license_uuid($licenseId));
    return hex2bin(
        substr($hex, 6, 2) . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2)
        . substr($hex, 10, 2) . substr($hex, 8, 2)
        . substr($hex, 14, 2) . substr($hex, 12, 2)
        . substr($hex, 16)
    ) ?: throw new InvalidArgumentException('The selected license ID is invalid.');
}
