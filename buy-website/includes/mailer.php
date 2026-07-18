<?php
declare(strict_types=1);

function email_activation_key(array $order): void
{
    $subject = 'Your POS Printer Emulator activation key';
    $tier = in_array(($order['license_tier'] ?? 'Pro'), ['Pro', 'Enterprise'], true) ? $order['license_tier'] : 'Pro';
    $body = "Hello {$order['customer_name']},\n\nYour payment has been confirmed for a {$tier} License.\n\nActivation key:\n{$order['activation_key']}\n\nIn POS Printer Emulator, open Settings > License and enter the same customer/company name and email used for this purchase.\n\nThank you,\nPOS Printer Emulator";
    $fromName = str_replace(["\r","\n"], '', (string) config('mail.from_name'));
    $fromEmail = str_replace(["\r","\n"], '', (string) config('mail.from_email'));
    $replyTo = str_replace(["\r","\n"], '', (string) config('mail.reply_to'));
    $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$replyTo}\r\nContent-Type: text/plain; charset=UTF-8";
    if (!mail($order['email'], $subject, $body, $headers)) throw new RuntimeException('The activation email could not be handed to the mail server.');
}
