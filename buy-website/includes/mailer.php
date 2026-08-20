<?php
declare(strict_types=1);

function purchase_email_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function purchase_portal_url(): string
{
    return strtolower((string)config('environment')) === 'sandbox'
        ? 'https://userportal-sandbox.posprinteremulator.com/portal.php?page=licenses'
        : 'https://userportal.posprinteremulator.com/portal.php?page=licenses';
}

function email_license_confirmation(array $order): void
{
    $tier = in_array(($order['license_tier'] ?? 'Pro'), paid_license_tiers(), true) ? $order['license_tier'] : 'Pro';
    $expiration = (string)($order['maintenance_new_expires_at'] ?? '');
    $renewal = (string)($order['order_type'] ?? 'LICENSE') === 'MAINTENANCE';
    $customerName = purchase_email_html((string)($order['customer_name'] ?? 'Customer'));
    $safeTier = purchase_email_html((string)$tier);
    $safeExpiration = purchase_email_html($expiration);
    $portalUrl = purchase_portal_url();
    if ($renewal) {
        $subject = 'Your POS Printer Emulator maintenance renewal';
        $headline = 'Maintenance and Support renewed';
        $summary = "Your one-time {$safeTier} Application Maintenance and Support renewal payment has been confirmed. This is not a software subscription. Your {$safeTier} License continues working after maintenance ends.";
        $detail = "Maintenance and Support is available through <strong>{$safeExpiration} UTC</strong>. Open POS Printer Emulator while connected to the internet and your registered computer will synchronize the updated coverage automatically.";
        $button = 'View License Coverage';
    } else {
        $subject = 'Your POS Printer Emulator license is ready';
        $headline = 'Your license is ready';
        $summary = "Your payment has been confirmed for a {$safeTier} License. One year of Application Maintenance and Support is included through <strong>{$safeExpiration} UTC</strong>.";
        $detail = 'Open POS Printer Emulator and select <strong>Settings &gt; License &gt; Link This Computer</strong>. Sign in to your verified Customer Portal account, review the computer, choose this license, and approve the link. The application will apply the license automatically.';
        $button = 'Manage Your License';
    }
    $fromName = str_replace(["\r","\n"], '', (string) config('mail.from_name'));
    $fromEmail = 'sales@buy.posprinteremulator.com';
    $body = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' .
        '<title>' . purchase_email_html($subject) . '</title></head>' .
        '<body style="margin:0;background:#f3f7fb;font-family:Arial,sans-serif;color:#0b1f38">' .
        '<div style="display:none;max-height:0;overflow:hidden">Your POS Printer Emulator account has been updated.</div>' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f7fb;padding:28px 12px"><tr><td align="center">' .
        '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#fff;border:1px solid #d7e3ef;border-radius:16px;overflow:hidden">' .
        '<tr><td style="background:#071b33;padding:24px 32px"><img src="https://www.posprinteremulator.com/assets/logo-web.png" width="220" alt="POS Printer Emulator" style="display:block;max-width:100%;height:auto"></td></tr>' .
        '<tr><td style="padding:32px"><p style="margin:0 0 12px;color:#0ea5c6;font-weight:700;text-transform:uppercase;font-size:12px">Purchase confirmed</p>' .
        '<h1 style="margin:0 0 18px;font-size:30px;line-height:1.2">' . purchase_email_html($headline) . '</h1>' .
        '<p style="font-size:16px;line-height:1.6">Hello ' . $customerName . ',</p>' .
        '<p style="font-size:16px;line-height:1.6">' . $summary . '</p>' .
        '<p style="font-size:16px;line-height:1.6">' . $detail . '</p>' .
        '<p style="margin:28px 0"><a href="' . purchase_email_html($portalUrl) . '" style="display:inline-block;background:#12bde3;color:#061a2f;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:8px">' . purchase_email_html($button) . '</a></p>' .
        '<p style="font-size:14px;line-height:1.6;color:#58708b">Maintenance renewal is optional. Purchased software features keep working after coverage ends.</p>' .
        '<hr style="border:0;border-top:1px solid #d7e3ef;margin:24px 0">' .
        '<p style="font-size:13px;line-height:1.6;color:#6b7f95">Please do not reply to this email. This inbox is not monitored. Visit the <a href="https://www.posprinteremulator.com/documentation" style="color:#087da0">documentation</a> or <a href="https://www.posprinteremulator.com/how-to-submit-a-support-request" style="color:#087da0">submit a support request</a>.</p>' .
        '</td></tr></table></td></tr></table></body></html>';
    $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
        "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8";
    if (!mail($order['email'], $subject, $body, $headers)) throw new RuntimeException('The customer email could not be handed to the mail server.');
}
