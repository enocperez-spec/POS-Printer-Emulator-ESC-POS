<?php
declare(strict_types=1);

function email_license_confirmation(array $order): void
{
    $tier = in_array(($order['license_tier'] ?? 'Pro'), paid_license_tiers(), true) ? $order['license_tier'] : 'Pro';
    $expiration = (string)($order['maintenance_new_expires_at'] ?? '');
    $renewal = (string)($order['order_type'] ?? 'LICENSE') === 'MAINTENANCE';
    if ($renewal) {
        $subject = 'Your POS Printer Emulator maintenance renewal';
        $body = "Hello {$order['customer_name']},\n\nYour one-time {$tier} Application Maintenance and Support renewal payment has been confirmed. This is not a software subscription. Your permanent {$tier} License continues working even after maintenance ends.\n\nMaintenance is available through:\n{$expiration} UTC\n\nOpen POS Printer Emulator while connected to the internet. Your registered computer will synchronize the updated coverage automatically.\n\nManage your license: https://userportal.posprinteremulator.com/portal.php?page=licenses\n\nThank you,\nPOS Printer Emulator";
    } else {
        $subject = 'Your POS Printer Emulator license is ready';
        $body = "Hello {$order['customer_name']},\n\nYour payment has been confirmed for a permanent {$tier} License. One year of Application Maintenance and Support is included through {$expiration} UTC.\n\nOpen POS Printer Emulator and select Settings > License > Link This Computer. Sign in to your verified Customer Portal account, review the computer, choose this license, and approve the link. The application will apply the license automatically.\n\nManage your license: https://userportal.posprinteremulator.com/portal.php?page=licenses\n\nMaintenance renewal is optional. The application and all purchased features keep working permanently after maintenance ends.\n\nThank you,\nPOS Printer Emulator";
    }
    $fromName = str_replace(["\r","\n"], '', (string) config('mail.from_name'));
    $fromEmail = 'sales@buy.posprinteremulator.com';
    $headers = "From: {$fromName} <{$fromEmail}>\r\nContent-Type: text/plain; charset=UTF-8";
    if (!mail($order['email'], $subject, $body, $headers)) throw new RuntimeException('The customer email could not be handed to the mail server.');
}
