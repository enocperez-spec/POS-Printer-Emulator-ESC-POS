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
        "SELECT license_id,license_tier,control_state,activation_key_ending,issued_at,
                maintenance_expires_at,maintenance_revoked_at
         FROM issued_licenses
         WHERE customer_id=:customer_id AND control_state<>'Deleted'
         ORDER BY issued_at DESC"
    );
    $licenseQuery->execute(['customer_id' => $customerId]);

    $installationQuery = $pdo->prepare(
        'SELECT id,installation_uuid,device_label,app_version,windows_version,license_mode,license_id,
                maintenance_status,maintenance_expires_at,first_seen_at,last_seen_at,portal_deactivated_at
         FROM installations
         WHERE customer_id=:customer_id
         ORDER BY portal_deactivated_at IS NULL DESC,last_seen_at DESC'
    );
    $installationQuery->execute(['customer_id' => $customerId]);

    $purchaseQuery = $pdo->prepare(
        'SELECT purchase_reference,order_type,license_tier,purchase_status,amount,currency,paid_at
         FROM customer_purchases WHERE customer_id=:customer_id
         ORDER BY paid_at DESC,updated_at DESC LIMIT 50'
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
    ];
}

function portal_masked_license(array $license): string
{
    $ending = strtoupper((string)($license['activation_key_ending'] ?? ''));
    return $ending === '' ? 'Not available' : '•••• ' . portal_e($ending);
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
            'activationKey' => $row['activation_key_ending'] ? '•••• ' . $row['activation_key_ending'] : null,
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
