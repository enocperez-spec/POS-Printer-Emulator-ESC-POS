<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function portal_customer_snapshot(string $customerId): array
{
    $pdo = portal_database();
    $customerQuery = $pdo->prepare(
        'SELECT customer_id,display_name,canonical_email,email_verified_at,status,created_at
         FROM customers WHERE customer_id=:customer_id AND status=\'Active\' LIMIT 1'
    );
    $customerQuery->execute(['customer_id' => $customerId]);
    $customer = $customerQuery->fetch();
    if (!is_array($customer)) {
        throw new RuntimeException('The customer record is unavailable.');
    }

    $licenseQuery = $pdo->prepare(
        "SELECT license_id,license_tier,control_state,issued_at,
                maintenance_expires_at,maintenance_revoked_at
         FROM issued_licenses
         WHERE customer_id=:customer_id AND control_state<>'Deleted'
         ORDER BY issued_at DESC"
    );
    $licenseQuery->execute(['customer_id' => $customerId]);

    $installationQuery = $pdo->prepare(
        "SELECT i.id,i.installation_uuid,i.device_label,i.app_version,i.windows_version,i.license_mode,i.license_id,
                i.maintenance_status,i.maintenance_expires_at,i.first_seen_at,i.last_seen_at,i.portal_deactivated_at,
                i.license_last_sync_at,i.license_last_sync_status,i.license_last_sync_error,
                RIGHT(HEX(i.device_fingerprint_hash),8) device_identifier_ending,
                b.activated_at linked_at,b.deactivated_at unlinked_at
         FROM installations i
         LEFT JOIN license_device_bindings b
           ON b.installation_id=i.id AND b.customer_id=i.customer_id
          AND b.binding_id=(
              SELECT b2.binding_id FROM license_device_bindings b2
              WHERE b2.installation_id=i.id ORDER BY b2.created_at DESC LIMIT 1
          )
         WHERE i.customer_id=:customer_id
         ORDER BY i.portal_deactivated_at IS NULL DESC,i.last_seen_at DESC"
    );
    $installationQuery->execute(['customer_id' => $customerId]);

    $purchaseQuery = $pdo->prepare(
        "SELECT p.purchase_reference,p.order_type,p.license_tier,p.purchase_status,p.amount,p.currency,
                p.paid_at,p.updated_at,
                i.order_type AS checkout_order_type,i.provider_order_id,i.provider_capture_id,
                i.license_id,i.replacement_license_id,i.maintenance_previous_expires_at,
                i.maintenance_new_expires_at,
                l.control_state AS license_control_state
         FROM customer_purchases p
         LEFT JOIN portal_checkout_intents i
           ON p.purchase_reference=CONCAT('portal:',i.intent_id) AND i.customer_id=p.customer_id
         LEFT JOIN issued_licenses l
           ON l.license_id=COALESCE(i.replacement_license_id,i.license_id) AND l.customer_id=p.customer_id
         WHERE p.customer_id=:customer_id
         ORDER BY p.paid_at DESC,p.updated_at DESC LIMIT 100"
    );
    $purchaseQuery->execute(['customer_id' => $customerId]);

    $supportQuery = $pdo->prepare(
        'SELECT reference_code,request_type,subject,github_issue_number,github_issue_url,state,created_at,submitted_at
         FROM support_requests WHERE customer_id=:customer_id
         ORDER BY created_at DESC LIMIT 100'
    );
    $supportQuery->execute(['customer_id' => $customerId]);
    $supportReplies = $pdo->prepare(
        'SELECT reference_code,author_type,message,created_at
         FROM portal_support_replies WHERE customer_id=:customer_id
         ORDER BY created_at'
    );
    $supportReplies->execute(['customer_id' => $customerId]);

    $consentQuery = $pdo->prepare(
        "SELECT cc.consent_type,cc.consent_state,cc.policy_version,cc.source,cc.recorded_at
         FROM customer_consents cc
         INNER JOIN (
             SELECT consent_type,MAX(id) latest_id
             FROM customer_consents WHERE customer_id=:customer_id GROUP BY consent_type
         ) latest ON latest.latest_id=cc.id
         ORDER BY cc.consent_type"
    );
    $consentQuery->execute(['customer_id' => $customerId]);
    $consentHistoryQuery = $pdo->prepare(
        'SELECT consent_type,consent_state,policy_version,source,recorded_at
         FROM customer_consents WHERE customer_id=:customer_id
         ORDER BY recorded_at DESC,id DESC LIMIT 100'
    );
    $consentHistoryQuery->execute(['customer_id' => $customerId]);

    $eventsQuery = $pdo->prepare(
        'SELECT event_type,event_summary,occurred_at
         FROM customer_events WHERE customer_id=:customer_id
         ORDER BY occurred_at DESC LIMIT 40'
    );
    $eventsQuery->execute(['customer_id' => $customerId]);
    $licenseActivityQuery = $pdo->prepare(
        'SELECT a.event_type,a.outcome,a.event_summary,a.created_at,
                i.device_label,i.installation_uuid
         FROM license_activation_events a
         LEFT JOIN installations i ON i.id=a.installation_id
         WHERE a.customer_id=:customer_id
         ORDER BY a.created_at DESC LIMIT 100'
    );
    $licenseActivityQuery->execute(['customer_id' => $customerId]);

    return [
        'customer' => $customer,
        'licenses' => $licenseQuery->fetchAll(),
        'installations' => $installationQuery->fetchAll(),
        'purchases' => $purchaseQuery->fetchAll(),
        'support' => $supportQuery->fetchAll(),
        'supportReplies' => $supportReplies->fetchAll(),
        'consents' => $consentQuery->fetchAll(),
        'consentHistory' => $consentHistoryQuery->fetchAll(),
        'events' => $eventsQuery->fetchAll(),
        'licenseActivity' => $licenseActivityQuery->fetchAll(),
    ];
}

function portal_masked_license(array $license): string
{
    $licenseId = strtoupper((string)($license['license_id'] ?? ''));
    return $licenseId === '' ? 'Not available' : 'License ' . portal_e(substr($licenseId, 0, 8));
}

function portal_listener_allowance(string $tier): string
{
    return match ($tier) {
        'Lite' => '1 listener',
        'Pro' => 'Up to 2 listeners',
        'Enterprise' => 'Up to 15 listeners recommended',
        default => 'Trial listener',
    };
}

function portal_date(?string $value, string $empty = 'Not available'): string
{
    if ($value === null || trim($value) === '') {
        return $empty;
    }
    $time = strtotime($value);
    return $time === false ? $empty : gmdate('M j, Y', $time);
}

function portal_long_date(?string $value, string $empty = 'Not available'): string
{
    if ($value === null || trim($value) === '') {
        return $empty;
    }
    $time = strtotime($value);
    return $time === false ? $empty : gmdate('F j, Y', $time);
}

function portal_customer_display_name(?string $displayName): string
{
    $name = trim((string)preg_replace('/\s+/', ' ', (string)$displayName));
    return $name !== '' ? $name : 'Customer';
}

function portal_license_status_label(?string $status): string
{
    $label = trim((string)$status);
    return strcasecmp($label, 'Enabled') === 0 ? 'Active' : ($label !== '' ? $label : 'Not available');
}

function portal_purchase_record(string $customerId, string $reference): ?array
{
    if ($reference === '' || strlen($reference) > 64 ||
        !preg_match('/^[A-Za-z0-9:_-]+$/', $reference)) {
        return null;
    }
    $query = portal_database()->prepare(
        "SELECT p.purchase_reference,p.order_type,p.license_tier,p.purchase_status,p.amount,p.currency,
                p.paid_at,p.updated_at,c.display_name,c.canonical_email,
                i.order_type AS checkout_order_type,i.provider_order_id,i.provider_capture_id,
                i.license_id,i.replacement_license_id,i.maintenance_previous_expires_at,
                i.maintenance_new_expires_at,
                l.control_state AS license_control_state
         FROM customer_purchases p
         INNER JOIN customers c ON c.customer_id=p.customer_id
         LEFT JOIN portal_checkout_intents i
           ON p.purchase_reference=CONCAT('portal:',i.intent_id) AND i.customer_id=p.customer_id
         LEFT JOIN issued_licenses l
           ON l.license_id=COALESCE(i.replacement_license_id,i.license_id) AND l.customer_id=p.customer_id
         WHERE p.customer_id=:customer_id AND p.purchase_reference=:reference
         LIMIT 1"
    );
    $query->execute(['customer_id' => $customerId, 'reference' => $reference]);
    $purchase = $query->fetch();
    return is_array($purchase) ? $purchase : null;
}

function portal_purchase_type_label(array $purchase): string
{
    return match ((string)($purchase['checkout_order_type'] ?? $purchase['order_type'] ?? '')) {
        'UPGRADE' => 'License upgrade',
        'MAINTENANCE' => 'Maintenance and Support renewal',
        default => 'POS Printer Emulator License',
    };
}

function portal_purchase_status_label(string $status): string
{
    return match (strtoupper(trim($status))) {
        'FULFILLED', 'COMPLETED', 'PAID' => 'Paid',
        'REFUNDED' => 'Refunded',
        'CANCELED', 'CANCELLED' => 'Canceled',
        'FAILED' => 'Failed',
        default => ucfirst(strtolower(trim($status))) ?: 'Pending',
    };
}

function portal_purchase_display_reference(array $purchase): string
{
    $providerReference = trim((string)($purchase['provider_order_id'] ?? ''));
    if ($providerReference !== '') {
        return $providerReference;
    }
    $reference = (string)($purchase['purchase_reference'] ?? '');
    return str_starts_with($reference, 'portal:') ? strtoupper(substr($reference, 7, 8)) : $reference;
}

function portal_purchase_license_label(array $purchase): string
{
    $tier = (string)($purchase['license_tier'] ?? 'License');
    return $tier . ' license';
}

function portal_normalize_version(?string $version): ?string
{
    $value = trim((string)$version);
    if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:\.0)?$/i', $value, $matches)) {
        return null;
    }
    if ((int)$matches[2] > 99 || (int)$matches[3] > 99) {
        return null;
    }
    return (int)$matches[1] . '.' . (int)$matches[2] . '.' . str_pad((string)(int)$matches[3], 2, '0', STR_PAD_LEFT);
}

function portal_version_ordinal(?string $version): ?int
{
    $normalized = portal_normalize_version($version);
    if ($normalized === null || !preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $normalized, $matches)) {
        return null;
    }
    return ((int)$matches[1] * 10000) + ((int)$matches[2] * 100) + (int)$matches[3];
}

/**
 * @return array{installedVersion:?string,latestVersion:?string,versionsBehind:?int,updateAvailable:?bool}
 */
function portal_version_status(?string $installedVersion, ?string $latestVersion): array
{
    $installed = portal_normalize_version($installedVersion);
    $latest = portal_normalize_version($latestVersion);
    $installedOrdinal = portal_version_ordinal($installed);
    $latestOrdinal = portal_version_ordinal($latest);
    if ($installedOrdinal === null || $latestOrdinal === null) {
        return [
            'installedVersion' => $installed,
            'latestVersion' => $latest,
            'versionsBehind' => null,
            'updateAvailable' => null,
        ];
    }
    $behind = max(0, $latestOrdinal - $installedOrdinal);
    return [
        'installedVersion' => $installed,
        'latestVersion' => $latest,
        'versionsBehind' => $behind,
        'updateAvailable' => $behind > 0,
    ];
}

function portal_primary_installation(array $installations): ?array
{
    foreach ($installations as $installation) {
        if (is_array($installation) && empty($installation['portal_deactivated_at'])) {
            return $installation;
        }
    }
    return null;
}

function portal_primary_active_license(array $licenses): ?array
{
    foreach ($licenses as $license) {
        if (is_array($license) && strcasecmp((string)($license['control_state'] ?? ''), 'Enabled') === 0) {
            return $license;
        }
    }
    return null;
}

function portal_has_active_maintenance(?array $license, ?DateTimeImmutable $now = null): bool
{
    if (!is_array($license) ||
        !empty($license['maintenance_revoked_at']) ||
        strcasecmp((string)($license['control_state'] ?? ''), 'Enabled') !== 0) {
        return false;
    }
    $expiresAt = trim((string)($license['maintenance_expires_at'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }
    try {
        $utc = new DateTimeZone('UTC');
        $expiration = new DateTimeImmutable($expiresAt, $utc);
        $current = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        return $expiration >= $current;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{currentVersion:string,releaseDate:string,releaseNotesUrl:string,downloadUrl:string}|null
 */
function portal_latest_release(): ?array
{
    $cached = $_SESSION['portal_release_manifest'] ?? null;
    if (is_array($cached) && (int)($cached['cachedAt'] ?? 0) >= time() - 3600) {
        return $cached['release'] ?? null;
    }

    $url = 'https://www.posprinteremulator.com/release.json';
    $body = null;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (is_string($response) && $status === 200) {
            $body = $response;
        }
    }

    $decoded = is_string($body) ? json_decode($body, true) : null;
    $version = is_array($decoded) ? portal_normalize_version((string)($decoded['currentVersion'] ?? '')) : null;
    if ($version === null) {
        return is_array($cached) && is_array($cached['release'] ?? null) ? $cached['release'] : null;
    }

    $releaseDate = (string)($decoded['releaseDate'] ?? '');
    $releaseNotesUrl = (string)($decoded['releaseNotesUrl'] ?? '');
    $downloadUrl = (string)($decoded['downloadUrl'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
        $releaseDate = '';
    }
    if (!preg_match('#^https://github\.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v\d+\.\d+\.\d+$#', $releaseNotesUrl)) {
        $releaseNotesUrl = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v' . $version;
    }
    if (!preg_match('#^https://www\.posprinteremulator\.com/downloads/POSPrinterEmulatorSetup-\d+\.\d+\.\d+-win-x64\.exe$#', $downloadUrl)) {
        $downloadUrl = 'https://www.posprinteremulator.com/downloads/POSPrinterEmulatorSetup-' . $version . '-win-x64.exe';
    }

    $release = [
        'currentVersion' => $version,
        'releaseDate' => $releaseDate,
        'releaseNotesUrl' => $releaseNotesUrl,
        'downloadUrl' => $downloadUrl,
    ];
    $_SESSION['portal_release_manifest'] = ['cachedAt' => time(), 'release' => $release];
    return $release;
}

/**
 * @return array{state:string,daysRemaining:?int,expirationDate:string}
 */
function portal_maintenance_reminder(?string $expiresAt, ?DateTimeImmutable $today = null): array
{
    $empty = ['state' => 'unavailable', 'daysRemaining' => null, 'expirationDate' => 'Not available'];
    if ($expiresAt === null || trim($expiresAt) === '') {
        return $empty;
    }

    try {
        $utc = new DateTimeZone('UTC');
        $expiration = (new DateTimeImmutable($expiresAt, $utc))->setTimezone($utc)->setTime(0, 0);
        $current = ($today ?? new DateTimeImmutable('now', $utc))->setTimezone($utc)->setTime(0, 0);
    } catch (Throwable) {
        return $empty;
    }

    $daysRemaining = (int)$current->diff($expiration)->format('%r%a');
    $state = $daysRemaining < 0
        ? 'expired'
        : ($current >= $expiration->modify('-3 months') ? 'expiring' : 'current');

    return [
        'state' => $state,
        'daysRemaining' => $daysRemaining,
        'expirationDate' => $expiration->format('F j, Y'),
    ];
}

function portal_datetime(?string $value, string $empty = 'Never'): string
{
    if ($value === null || trim($value) === '') {
        return $empty;
    }
    $time = strtotime($value);
    return $time === false ? $empty : gmdate('M j, Y g:i A', $time) . ' UTC';
}

function portal_customer_export(array $snapshot): array
{
    return [
        'exportedAt' => gmdate(DATE_ATOM),
        'customer' => [
            'id' => $snapshot['customer']['customer_id'],
            'name' => $snapshot['customer']['display_name'],
            'email' => $snapshot['customer']['canonical_email'],
            'emailVerifiedAt' => $snapshot['customer']['email_verified_at'],
            'status' => $snapshot['customer']['status'],
        ],
        'licenses' => array_map(static fn(array $row): array => [
            'licenseId' => $row['license_id'],
            'tier' => $row['license_tier'],
            'status' => $row['control_state'],
            'issuedAt' => $row['issued_at'],
            'maintenanceExpiresAt' => $row['maintenance_expires_at'],
        ], $snapshot['licenses']),
        'installations' => array_map(static fn(array $row): array => [
            'installationId' => $row['installation_uuid'],
            'label' => $row['device_label'],
            'appVersion' => $row['app_version'],
            'licenseTier' => $row['license_mode'],
            'firstSeenAt' => $row['first_seen_at'],
            'lastSeenAt' => $row['last_seen_at'],
            'deactivatedAt' => $row['portal_deactivated_at'],
        ], $snapshot['installations']),
        'purchases' => $snapshot['purchases'],
        'supportRequests' => array_map(static fn(array $row): array => [
            'reference' => $row['reference_code'],
            'type' => $row['request_type'],
            'subject' => $row['subject'],
            'state' => $row['state'],
            'createdAt' => $row['created_at'],
        ], $snapshot['support']),
        'consents' => $snapshot['consentHistory'],
        'activity' => $snapshot['events'],
    ];
}
