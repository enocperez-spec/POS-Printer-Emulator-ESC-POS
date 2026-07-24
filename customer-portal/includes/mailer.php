<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function portal_queue_mail(
    string $customerId,
    string $recipient,
    string $type,
    string $subject,
    string $body,
    array $templateParameters = []
): void
{
    $pdo = portal_database();
    $templateKey = match ($type) {
        'Portal Enrollment' => 'email_verification',
        'Password Reset' => 'password_recovery',
        default => null,
    };
    if ($templateKey !== null && portal_try_communication_outbox(
        $pdo,
        $customerId,
        $recipient,
        $templateKey,
        $templateParameters,
        hash('sha256', $type . '|' . $customerId . '|' . $body)
    )) {
        portal_kick_communication_worker();
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO portal_mail_outbox(customer_id,message_type,recipient_email,subject,text_body)
         VALUES(:customer_id,:type,:recipient,:subject,:body)'
    );
    $insert->execute([
        'customer_id' => $customerId,
        'type' => mb_substr($type, 0, 40),
        'recipient' => $recipient,
        'subject' => mb_substr($subject, 0, 180),
        'body' => $body,
    ]);

    $transport = strtolower((string)(portal_config()['portal']['mail_transport'] ?? 'outbox'));
    if ($transport !== 'php_mail') {
        return;
    }
    $mailId = (int)$pdo->lastInsertId();
    $from = portal_normalize_email((string)(portal_config()['portal']['mail_from'] ?? ''));
    if ($from === '') {
        return;
    }
    $body = rtrim($body) . portal_mail_support_footer($pdo);
    $headers = [
        'From: POS Printer Emulator <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress: All',
    ];
    $sent = @mail($recipient, $subject, $body, implode("\r\n", $headers));
    $update = $pdo->prepare(
        "UPDATE portal_mail_outbox
         SET state=:state,attempts=attempts+1,sent_at=CASE WHEN :sent=1 THEN UTC_TIMESTAMP(6) ELSE NULL END,
             last_error=CASE WHEN :sent=1 THEN NULL ELSE 'PHP mail transport rejected the message.' END
         WHERE id=:id"
    );
    $update->execute(['state' => $sent ? 'Sent' : 'Failed', 'sent' => $sent ? 1 : 0, 'id' => $mailId]);
}

function portal_kick_communication_worker(): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $portal = portal_config()['portal'] ?? [];
    $url = trim((string)($portal['communications_worker_url'] ?? ''));
    $token = trim((string)($portal['support_backend_token'] ?? ''));
    $parts = parse_url($url);
    if (!is_array($parts) ||
        ($parts['scheme'] ?? '') !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== 'admin.posprinteremulator.com' ||
        strlen($token) < 43) {
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($response) && $status >= 200 && $status < 300;
}

function portal_try_communication_outbox(
    PDO $pdo,
    string $customerId,
    string $recipient,
    string $templateKey,
    array $parameters,
    string $idempotencyDigest
): bool {
    $parameters = array_replace($parameters, portal_mail_global_parameters($pdo));
    $allowedKeys = [
        'customer_name', 'verification_url', 'reset_url', 'documentation_url',
        'help_center_url', 'support_request_url', 'no_reply_notice',
    ];
    $clean = [];
    foreach ($parameters as $key => $value) {
        if (!in_array((string)$key, $allowedKeys, true) || !is_string($value) || strlen($value) > 500) {
            return false;
        }
        $key = (string)$key;
        $value = trim($value);
        if (str_ends_with($key, '_url') && !portal_mail_url_is_allowed($value)) {
            return false;
        }
        if ($key === 'no_reply_notice' &&
            $value !== 'Please do not reply to this email. This inbox is not monitored.') {
            return false;
        }
        $clean[$key] = $value;
    }
    try {
        $template = $pdo->prepare(
            "SELECT message_class,essential FROM communication_templates
             WHERE template_key=:key AND enabled=1 AND brevo_template_id IS NOT NULL LIMIT 1"
        );
        $template->execute(['key' => $templateKey]);
        $row = $template->fetch();
        if (!is_array($row) || (string)$row['message_class'] !== 'Service') return false;
        $messageId = portal_mail_uuid();
        $priority = in_array($templateKey, ['email_verification', 'password_recovery'], true) ? 10 : 50;
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO communication_outbox
                (message_id,customer_id,template_key,message_class,essential,manual_send,priority,recipient_hash,
                 parameters_json,idempotency_key)
             VALUES(:message_id,:customer_id,:template_key,\'Service\',:essential,0,:priority,
                    UNHEX(SHA2(:recipient,256)),:parameters,:idempotency_key)'
        );
        $insert->execute([
            'message_id' => $messageId,
            'customer_id' => $customerId,
            'template_key' => $templateKey,
            'essential' => (int)$row['essential'],
            'priority' => $priority,
            'recipient' => portal_normalize_email($recipient),
            'parameters' => json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'idempotency_key' => 'portal:' . $templateKey . ':' . $idempotencyDigest,
        ]);
        // A duplicate is already durably queued, so it is also a successful handoff.
        return true;
    } catch (PDOException $exception) {
        if (in_array((string)$exception->getCode(), ['42S02', '42S22'], true)) return false;
        throw $exception;
    }
}

function portal_mail_global_parameters(PDO $pdo): array
{
    $settings = [
        'documentation_url' => 'https://www.posprinteremulator.com/documentation',
        'help_center_url' => 'https://www.posprinteremulator.com/documentation',
        'support_request_url' => 'https://www.posprinteremulator.com/how-to-submit-a-support-request',
        'no_reply_notice' => 'Please do not reply to this email. This inbox is not monitored.',
    ];
    try {
        $query = $pdo->prepare(
            "SELECT setting_key,setting_value
             FROM communication_settings
             WHERE setting_key IN
               ('documentation_url','help_center_url','support_request_url','no_reply_notice')"
        );
        $query->execute();
        foreach ($query->fetchAll() as $row) {
            $key = (string)$row['setting_key'];
            $value = trim((string)$row['setting_value']);
            if (str_ends_with($key, '_url') && portal_mail_url_is_allowed($value)) {
                $settings[$key] = $value;
            } elseif ($key === 'no_reply_notice' &&
                $value === 'Please do not reply to this email. This inbox is not monitored.') {
                $settings[$key] = $value;
            }
        }
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() !== '42S02') {
            throw $exception;
        }
    }
    return $settings;
}

function portal_mail_support_footer(PDO $pdo): string
{
    $settings = portal_mail_global_parameters($pdo);
    return "\n\nNeed help with POS Printer Emulator? Our complete documentation and troubleshooting guides are available online."
        . "\nView Documentation: " . $settings['documentation_url']
        . "\nSubmit a Support Request: " . $settings['support_request_url']
        . "\n\n" . $settings['no_reply_notice'];
}

function portal_mail_url_is_allowed(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && in_array(strtolower((string)($parts['host'] ?? '')), [
            'www.posprinteremulator.com',
            'userportal.posprinteremulator.com',
        ], true)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function portal_mail_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}
