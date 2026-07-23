<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function portal_queue_mail(string $customerId, string $recipient, string $type, string $subject, string $body): void
{
    $pdo = portal_database();
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
    $reply = portal_normalize_email((string)(portal_config()['portal']['mail_reply_to'] ?? $from));
    if ($from === '' || $reply === '') {
        return;
    }
    $headers = [
        'From: POS Printer Emulator <' . $from . '>',
        'Reply-To: ' . $reply,
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
