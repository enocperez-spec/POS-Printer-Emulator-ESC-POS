<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/communications.php';
require_once dirname(__DIR__, 2) . '/includes/license_management.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function license_delivery_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function license_delivery_audit(
    PDO $pdo,
    string $customerId,
    string $eventType,
    string $summary,
    string $licenseId
): void {
    $insert = $pdo->prepare(
        "INSERT INTO customer_events(customer_id,event_type,source,source_reference,actor,event_summary)
         VALUES(:customer_id,:event_type,'Customer Portal',:license_id,'Portal License Delivery',:summary)"
    );
    $insert->execute([
        'customer_id' => $customerId,
        'event_type' => mb_substr($eventType, 0, 64),
        'license_id' => $licenseId,
        'summary' => mb_substr($summary, 0, 500),
    ]);
}

function license_delivery_html(array $license, string $activationKey): string
{
    $name = htmlspecialchars((string)$license['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $tier = htmlspecialchars((string)$license['license_tier'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $registeredName = htmlspecialchars((string)$license['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $registeredEmail = htmlspecialchars((string)$license['email_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $key = htmlspecialchars($activationKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $expiration = (new DateTimeImmutable((string)$license['maintenance_expires_at'], new DateTimeZone('UTC')))
        ->format('F j, Y');
    return '<!doctype html><html><body style="margin:0;background:#eef4f8;font-family:Arial,sans-serif;color:#10213a">' .
        '<div style="max-width:640px;margin:0 auto;padding:28px 16px"><div style="background:#fff;border-radius:16px;overflow:hidden;border:1px solid #dce5ee">' .
        '<div style="background:#07172d;padding:22px"><img src="https://www.posprinteremulator.com/assets/logo-web.png" alt="POS Printer Emulator" style="max-width:240px;height:auto"></div>' .
        '<div style="padding:30px"><h1 style="margin-top:0">Your activation key</h1><p>Hello ' . $name . ',</p>' .
        '<p>Your ' . $tier . ' activation key was requested through the secure Customer Portal. Maintenance and Support is active through <strong>' .
        htmlspecialchars($expiration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>' .
        '<p>Use the following registration details in <strong>Settings → License</strong>:</p>' .
        '<p><strong>Customer or company:</strong> ' . $registeredName . '<br><strong>Registration email:</strong> ' . $registeredEmail . '</p>' .
        '<div style="word-break:break-all;background:#eef4f8;border:1px solid #cbd8e5;border-radius:10px;padding:16px;font-family:Consolas,monospace">' . $key . '</div>' .
        '<p style="margin-top:24px"><a href="https://www.posprinteremulator.com/documentation" style="display:inline-block;background:#0878ef;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold">View Documentation</a></p>' .
        '<p style="color:#617087;font-size:13px">Please do not reply to this email. This inbox is not monitored. If you did not request this key, change your Customer Portal password and submit a support request.</p>' .
        '</div></div></div></body></html>';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    license_delivery_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    license_delivery_response(['error' => 'Authentication failed.'], 401);
}

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    $customerId = trim((string)($body['customerId'] ?? ''));
    $licenseId = trim((string)($body['licenseId'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $customerId) || !preg_match('/^[0-9a-f-]{36}$/i', $licenseId)) {
        license_delivery_response(['error' => 'Invalid request.'], 422);
    }

    $pdo = database();
    ensure_license_management_schema($pdo);
    $query = $pdo->prepare(
        "SELECT l.license_id,l.customer_name,l.email_address,l.license_tier,l.control_state,
                l.maintenance_expires_at,l.maintenance_revoked_at,l.activation_key,
                l.activation_key_ciphertext,l.activation_key_nonce,l.activation_key_tag,
                c.customer_id,c.display_name,c.canonical_email,c.email_verified_at,c.status customer_status
         FROM issued_licenses l
         INNER JOIN customers c ON c.customer_id=l.customer_id
         WHERE l.license_id=:license_id AND l.customer_id=:customer_id
         LIMIT 1"
    );
    $query->execute(['license_id' => $licenseId, 'customer_id' => $customerId]);
    $license = $query->fetch();
    if (!is_array($license) || (string)$license['customer_status'] !== 'Active' ||
        (string)$license['control_state'] !== 'Enabled') {
        license_delivery_response(['error' => 'The selected license is not eligible.'], 404);
    }

    $activeCoverage = empty($license['maintenance_revoked_at']) &&
        strtotime((string)$license['maintenance_expires_at']) >= time();
    if (!$activeCoverage) {
        license_delivery_audit(
            $pdo,
            $customerId,
            'Activation Resend Restricted',
            'Activation-key resend was blocked because Maintenance and Support was not active.',
            $licenseId
        );
        license_delivery_response([
            'error' => 'Maintenance and Support renewal is required before this activation key can be resent.',
            'code' => 'maintenance_required',
        ], 409);
    }
    if (empty($license['email_verified_at']) ||
        !hash_equals(strtolower((string)$license['canonical_email']), strtolower((string)$license['email_address']))) {
        license_delivery_audit(
            $pdo,
            $customerId,
            'Activation Resend Restricted',
            'Activation-key resend was blocked because the verified account email did not match the license registration email.',
            $licenseId
        );
        license_delivery_response(['error' => 'The verified account email does not match this license. Submit a support request.'], 409);
    }

    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM customer_events
         WHERE customer_id=:customer_id AND event_type='Activation Key Resent'
           AND source_reference=:license_id AND occurred_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)"
    );
    $recent->execute(['customer_id' => $customerId, 'license_id' => $licenseId]);
    if ((int)$recent->fetchColumn() >= 3) {
        license_delivery_audit(
            $pdo,
            $customerId,
            'Activation Resend Restricted',
            'Activation-key resend was rate limited after three successful deliveries in one hour.',
            $licenseId
        );
        license_delivery_response(['error' => 'This activation key was recently resent. Wait before trying again.'], 429);
    }

    $activationKey = reveal_activation_key($license);
    if (!str_starts_with($activationKey, 'PPE1-')) {
        throw new RuntimeException('The activation key could not be recovered.');
    }
    $config = communication_config();
    $sender = communication_template_sender($pdo, 'activation_ready', 'Service', $config);
    if (empty($config['enabled'])) {
        throw new RuntimeException('Transactional email is unavailable.');
    }
    $expiration = (new DateTimeImmutable((string)$license['maintenance_expires_at'], new DateTimeZone('UTC')))
        ->format('F j, Y');
    communication_brevo_request('POST', '/smtp/email', [
        'sender' => ['email' => $sender['email'], 'name' => $sender['name']],
        'to' => [['email' => (string)$license['canonical_email'], 'name' => (string)$license['display_name']]],
        'subject' => 'Your POS Printer Emulator activation key',
        'htmlContent' => license_delivery_html($license, $activationKey),
        'textContent' => "Hello {$license['display_name']},\n\nYour {$license['license_tier']} activation key was requested through the secure Customer Portal. Maintenance and Support is active through {$expiration}.\n\nCustomer or company: {$license['customer_name']}\nRegistration email: {$license['email_address']}\n\nActivation key:\n{$activationKey}\n\nView documentation: https://www.posprinteremulator.com/documentation\n\nPlease do not reply to this email. This inbox is not monitored.",
        'headers' => ['X-Auto-Response-Suppress' => 'All'],
    ]);
    license_delivery_audit(
        $pdo,
        $customerId,
        'Activation Key Resent',
        'Activation key was resent to the verified account email while Maintenance and Support was active.',
        $licenseId
    );
    license_delivery_response(['ok' => true]);
} catch (Throwable $exception) {
    error_log('Customer Portal activation delivery failed: ' . get_class($exception));
    if (isset($pdo, $customerId, $licenseId) && $pdo instanceof PDO &&
        is_string($customerId) && is_string($licenseId) &&
        preg_match('/^[0-9a-f-]{36}$/i', $customerId) && preg_match('/^[0-9a-f-]{36}$/i', $licenseId)) {
        try {
            license_delivery_audit(
                $pdo,
                $customerId,
                'Activation Resend Failed',
                'Activation-key resend failed before delivery confirmation.',
                $licenseId
            );
        } catch (Throwable) {
        }
    }
    license_delivery_response(['error' => 'The activation key could not be sent. Try again later.'], 503);
}
