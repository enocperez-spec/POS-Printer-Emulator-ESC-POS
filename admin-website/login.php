<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['two_factor_verified'])) {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $attempts = (int)($_SESSION['login_attempts'] ?? 0);
    if ($attempts >= 8) {
        usleep(1500000);
        $error = 'Too many attempts. Close the browser and try again later.';
    } elseif (verify_admin_password(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['password_verified'] = true;
        $_SESSION['two_factor_verified'] = false;
        $_SESSION['login_attempts'] = 0;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: ' . (two_factor_secret() === null ? '/two-factor-setup.php' : '/two-factor.php'));
        exit;
    } else {
        $_SESSION['login_attempts'] = $attempts + 1;
        usleep(600000);
        $error = 'The username or password was not recognized.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Owner sign in | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"></head><body class="login-page"><main class="login-panel"><img src="assets/icon-web.png" alt=""><h1>POS Printer Emulator</h1><p>Sign in to the protected owner portal.</p><?php if ($error !== ''): ?><div class="login-error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Sign in</button></form><small>Dashboard and License Manager access is restricted.</small></main></body></html>
