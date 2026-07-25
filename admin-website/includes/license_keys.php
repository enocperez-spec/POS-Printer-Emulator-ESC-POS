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

function issue_promotion_token(
    string $promotionId,
    string $subjectType,
    string $subjectId,
    string $previousTier,
    string $grantedTier,
    DateTimeImmutable $issuedAt,
    DateTimeImmutable $expiresAt,
    ?string $privateKeyOverride = null
): string {
    $subjectValue = match ($subjectType) {
        'License' => 1,
        'Installation' => 2,
        default => throw new InvalidArgumentException('The promotion subject is invalid.'),
    };
    $previousValue = $previousTier === 'Trial' ? 0 : activation_tier_value($previousTier);
    $grantedValue = activation_tier_value($grantedTier);
    $rank = ['Trial'=>0,'Lite'=>1,'Pro'=>2,'Enterprise'=>3];
    if (!isset($rank[$previousTier],$rank[$grantedTier]) ||
        $rank[$grantedTier] <= $rank[$previousTier] ||
        $expiresAt <= $issuedAt ||
        $expiresAt->getTimestamp() - $issuedAt->getTimestamp() > 5 * 86400 + 300) {
        throw new InvalidArgumentException('The promotion claims are invalid.');
    }
    $payload = chr(1)
        . dotnet_guid_bytes(canonical_license_uuid($promotionId))
        . chr($subjectValue)
        . dotnet_guid_bytes(canonical_license_uuid($subjectId))
        . pack_unix_seconds($issuedAt->getTimestamp())
        . pack_unix_seconds($expiresAt->getTimestamp())
        . chr($previousValue)
        . chr($grantedValue);
    if (strlen($payload) !== 52) {
        throw new RuntimeException('The promotion payload has an unexpected length.');
    }
    return 'PPEP1-' . rtrim(strtr(base64_encode($payload . sign_license_payload($payload,$privateKeyOverride)), '+/', '-_'), '=');
}

function create_license_entitlement(
    string $customerName,
    string $emailAddress,
    string $licenseTier = 'Pro',
    ?string $maintenanceExpiresAt = null
): array {
    $customerName = trim(preg_replace('/\s+/', ' ', $customerName) ?? '');
    $emailAddress = strtolower(trim($emailAddress));
    if ($customerName === '' || strlen($customerName) > 160) {
        throw new InvalidArgumentException('Customer or company name is required.');
    }
    if (strlen($emailAddress) > 254 || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    activation_tier_value($licenseTier);
    $issuedAt = time();
    $maintenance = normalize_maintenance_expiration($maintenanceExpiresAt, $issuedAt, false);
    return [
        'license_id' => dotnet_guid_string(random_bytes(16)),
        'issued_at' => gmdate('Y-m-d H:i:s', $issuedAt),
        'customer_name' => $customerName,
        'email_address' => $emailAddress,
        'license_tier' => $licenseTier,
        'maintenance_expires_at' => $maintenance->format('Y-m-d H:i:s'),
    ];
}

function issue_device_entitlement(
    string $licenseId,
    string $customerId,
    string $installationUuid,
    string $licenseTier,
    string $maintenanceExpiresAt,
    int $revision,
    ?string $privateKeyOverride = null
): string {
    if ($revision < 1) {
        throw new InvalidArgumentException('The entitlement revision is invalid.');
    }
    $issuedAt = time();
    $validUntil = $issuedAt + 86400;
    $maintenance = normalize_maintenance_expiration($maintenanceExpiresAt, $issuedAt, false);
    $payload = chr(2)
        . dotnet_guid_bytes(canonical_license_uuid($licenseId))
        . dotnet_guid_bytes(canonical_license_uuid($customerId))
        . dotnet_guid_bytes(canonical_license_uuid($installationUuid))
        . pack_unix_seconds($issuedAt)
        . pack_unix_seconds($validUntil)
        . pack_unix_seconds($maintenance->getTimestamp())
        . chr(activation_tier_value($licenseTier))
        . pack_unix_seconds($revision);
    if (strlen($payload) !== 82) {
        throw new RuntimeException('The device entitlement payload has an unexpected length.');
    }
    return 'PPED1-' . rtrim(strtr(
        base64_encode($payload . sign_license_payload($payload, $privateKeyOverride)),
        '+/',
        '-_'
    ), '=');
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
