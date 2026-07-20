<?php
declare(strict_types=1);

function email_activation_key(array $order): void
{
    $tier = in_array(($order['license_tier'] ?? 'Pro'), paid_license_tiers(), true) ? $order['license_tier'] : 'Pro';
    $expiration = (string)($order['maintenance_new_expires_at'] ?? '');
    $renewal = (string)($order['order_type'] ?? 'LICENSE') === 'MAINTENANCE';
    if ($renewal) {
        $subject = 'Your POS Printer Emulator maintenance renewal';
        $body = "Hello {$order['customer_name']},\n\nYour one-time {$tier} Application Maintenance and Support renewal payment has been confirmed. This is not a software subscription. Your permanent {$tier} License continues working even after maintenance ends.\n\nMaintenance is available through:\n{$expiration} UTC\n\nMaintenance entitlement token:\n{$order['maintenance_token']}\n\nOpen Settings > License while connected to the internet to refresh the entitlement automatically. The token above can also be entered if an offline renewal option is offered.\n\nThank you,\nPOS Printer Emulator";
    } else {
        $subject = 'Your POS Printer Emulator activation key';
        $body = "Hello {$order['customer_name']},\n\nYour payment has been confirmed for a permanent {$tier} License. One year of Application Maintenance and Support is included through {$expiration} UTC.\n\nActivation key:\n{$order['activation_key']}\n\nIn POS Printer Emulator, open Settings > License and enter the same customer/company name and email used for this purchase.\n\nMaintenance renewal is optional. The application and all purchased features keep working permanently after maintenance ends.\n\nThank you,\nPOS Printer Emulator";
    }
    $fromName = str_replace(["\r","\n"], '', (string) config('mail.from_name'));
    $fromEmail = str_replace(["\r","\n"], '', (string) config('mail.from_email'));
    $replyTo = str_replace(["\r","\n"], '', (string) config('mail.reply_to'));
    $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$replyTo}\r\nContent-Type: text/plain; charset=UTF-8";
    if (!mail($order['email'], $subject, $body, $headers)) throw new RuntimeException('The customer email could not be handed to the mail server.');
}
