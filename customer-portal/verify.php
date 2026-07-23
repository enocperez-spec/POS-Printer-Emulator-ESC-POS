<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$purpose = (string)($_GET['purpose'] ?? $_POST['purpose'] ?? '');
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$record = in_array($purpose, ['enroll', 'reset'], true) ? portal_verification_record($purpose, $token) : null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_require_csrf();
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } elseif (!portal_password_is_valid($password)) {
        $error = 'Use at least 12 characters with an uppercase letter, lowercase letter, and number.';
    } else {
        try {
            if (portal_set_password_from_token($purpose, $token, $password)) {
                $_SESSION['portal_notice'] = $purpose === 'enroll'
                    ? 'Your Customer Portal account is ready. Sign in with your new password.'
                    : 'Your password was reset. Sign in with your new password.';
                portal_redirect('/index.php');
            }
            $error = 'This link is invalid or has expired. Request a new link.';
        } catch (Throwable $exception) {
            error_log('Customer Portal verification error: ' . get_class($exception));
            $error = 'The password could not be saved. Request a new link and try again.';
        }
    }
    $record = portal_verification_record($purpose, $token);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $purpose === 'reset' ? 'Reset password' : 'Create account' ?> | POS Printer Emulator</title>
  <link rel="icon" href="/assets/favicon.png" type="image/png">
  <link rel="stylesheet" href="/assets/portal.css">
</head>
<body class="single-form-page">
<main class="single-form">
  <a class="brand-lockup dark" href="/index.php"><img src="/assets/product-icon.png" width="48" height="48" alt=""><span><strong>POS Printer Emulator</strong><small>Secure Customer Portal</small></span></a>
  <?php if (!is_array($record)): ?>
    <h1>This link is unavailable</h1>
    <p>It may have expired or already been used. Return to sign in to request a new protected link.</p>
    <a class="button primary" href="/index.php">Return to sign in</a>
  <?php else: ?>
    <h1><?= $purpose === 'reset' ? 'Choose a new password' : 'Create your portal password' ?></h1>
    <p>Customer record for <?= portal_e((string)$record['canonical_email']) ?>.</p>
    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= portal_e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
      <input type="hidden" name="purpose" value="<?= portal_e($purpose) ?>">
      <input type="hidden" name="token" value="<?= portal_e($token) ?>">
      <label>New password<input type="password" name="password" maxlength="200" autocomplete="new-password" required></label>
      <label>Confirm new password<input type="password" name="password_confirmation" maxlength="200" autocomplete="new-password" required></label>
      <p class="field-help">At least 12 characters with uppercase, lowercase, and a number.</p>
      <button class="button primary" type="submit"><?= $purpose === 'reset' ? 'Reset password' : 'Create account' ?></button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
