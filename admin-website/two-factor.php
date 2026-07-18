<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require_password_authentication();

$secret = two_factor_secret();
if ($secret === null) {
    header('Location: /two-factor-setup.php');
    exit;
}
if (!empty($_SESSION['two_factor_verified'])) {
    header('Location: /');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $attempts = (int)($_SESSION['two_factor_attempts'] ?? 0);
    if ($attempts >= 8) {
        usleep(1500000);
        $error = 'Too many attempts. Sign in again to continue.';
        $_SESSION['password_verified'] = false;
    } elseif (verify_totp($secret, (string)($_POST['code'] ?? ''))) {
        $_SESSION['two_factor_verified'] = true;
        $_SESSION['two_factor_attempts'] = 0;
        session_regenerate_id(true);
        header('Location: /');
        exit;
    } else {
        $_SESSION['two_factor_attempts'] = $attempts + 1;
        usleep(600000);
        $error = 'That code was not accepted. Try the current code from your authenticator app.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Two-factor verification | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-3"><link rel="stylesheet" href="assets/two-factor.css?v=20260714-3"></head><body class="login-page"><main class="two-factor-panel compact"><div class="security-mark" aria-hidden="true">✓</div><h1>Two-factor verification</h1><p>Enter the six-digit code from your authenticator app.</p><?php if ($error !== ''): ?><div class="login-error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Authentication code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus></label><button type="submit">Verify</button></form><form method="post" action="/logout.php" class="signout-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit">Return to sign in</button></form></main></body></html>
