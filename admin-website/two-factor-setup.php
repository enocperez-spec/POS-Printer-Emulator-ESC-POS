<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require_password_authentication();

if (two_factor_secret() !== null) {
    header('Location: /two-factor.php');
    exit;
}

if (empty($_SESSION['pending_two_factor_secret'])) {
    $_SESSION['pending_two_factor_secret'] = generate_two_factor_secret();
}
$secret = (string)$_SESSION['pending_two_factor_secret'];
$issuer = 'POS Printer Emulator';
$account = (string)private_config()['admin']['username'];
$uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
    . '?secret=' . rawurlencode($secret)
    . '&issuer=' . rawurlencode($issuer)
    . '&algorithm=SHA1&digits=6&period=30';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (verify_totp($secret, (string)($_POST['code'] ?? ''))) {
        save_two_factor_secret($secret);
        unset($_SESSION['pending_two_factor_secret']);
        $_SESSION['two_factor_verified'] = true;
        $_SESSION['two_factor_verified_at'] = time();
        $_SESSION['two_factor_attempts'] = 0;
        session_regenerate_id(true);
        header('Location: /');
        exit;
    }
    usleep(600000);
    $error = 'That code was not accepted. Wait for a new code and try again.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Set up two-factor authentication | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-3"><link rel="stylesheet" href="assets/two-factor.css?v=20260714-3"></head><body class="login-page"><main class="two-factor-panel"><div class="security-mark" aria-hidden="true">2</div><h1>Secure your admin access</h1><p>Scan this QR code with Microsoft Authenticator, Google Authenticator, 1Password, or another TOTP app.</p><?php if ($error !== ''): ?><div class="login-error" role="alert"><?= e($error) ?></div><?php endif; ?><div id="qr-code" data-otp-uri="<?= e($uri) ?>" aria-label="Authenticator setup QR code"></div><details><summary>Cannot scan the QR code?</summary><p>Enter this setup key manually:</p><code><?= e(implode(' ', str_split($secret, 4))) ?></code></details><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Enter the six-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus></label><button type="submit">Verify and enable 2FA</button></form><small>The QR code is displayed once during enrollment.</small></main><script src="assets/vendor/qrcodejs/qrcode.min.js"></script><script src="assets/two-factor.js?v=20260714-3"></script></body></html>
