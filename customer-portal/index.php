<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (portal_current_account() !== null) {
    portal_redirect('/portal.php');
}

$error = '';
$notice = (string)($_SESSION['portal_notice'] ?? '');
unset($_SESSION['portal_notice']);
$email = portal_normalize_email((string)($_POST['email'] ?? ''));
$mfaPending = !empty($_SESSION['mfa_pending']);
$activePanel = 'sign-in';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $activePanel = match ($action) {
        'enroll' => 'verify',
        'reset' => 'reset',
        default => 'sign-in',
    };
    try {
        if ($action === 'login') {
            if ($email === '') {
                $error = 'Enter a valid email address.';
            } else {
                [$valid, $message] = portal_verify_password_login($email, (string)($_POST['password'] ?? ''));
                if ($valid && empty($_SESSION['mfa_pending'])) {
                    portal_redirect('/portal.php');
                }
                $mfaPending = !empty($_SESSION['mfa_pending']);
                $error = $valid ? '' : $message;
            }
        } elseif ($action === 'mfa') {
            if (portal_complete_mfa((string)($_POST['code'] ?? ''))) {
                portal_redirect('/portal.php');
            }
            $mfaPending = true;
            $error = 'The authenticator or recovery code was not accepted.';
        } elseif ($action === 'enroll') {
            if ($email !== '') {
                portal_request_enrollment($email);
            }
            $notice = 'If a matching customer record is eligible, you will receive an email with the next steps.';
        } elseif ($action === 'reset') {
            if ($email !== '') {
                portal_request_password_reset($email);
            }
            $notice = 'If a matching portal account exists, you will receive an email with the next steps.';
        }
    } catch (Throwable $exception) {
        error_log('Customer Portal authentication error: ' . get_class($exception));
        $error = 'The Customer Portal could not complete that request. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign in | POS Printer Emulator Customer Portal</title>
  <link rel="icon" href="/assets/favicon.png" type="image/png">
  <link rel="stylesheet" href="/assets/portal.css">
  <script src="/assets/portal-auth.js" defer></script>
</head>
<body class="auth-page">
<main class="auth-shell">
  <aside class="auth-brand" aria-label="Customer Portal information">
    <a class="brand-lockup" href="https://www.posprinteremulator.com/" rel="noopener">
      <img src="/assets/product-icon.png" width="54" height="54" alt="">
      <span><strong>POS Printer Emulator</strong><small>Secure Customer Portal</small></span>
    </a>
    <div class="auth-trust">
      <section><span class="trust-icon" aria-hidden="true">◇</span><div><h2>Secure by design</h2><p>Protected sessions, verified ownership, and careful data handling.</p></div></section>
      <section><span class="trust-icon" aria-hidden="true">○</span><div><h2>Verified accounts</h2><p>We verify your email before customer records become available.</p></div></section>
      <section><span class="trust-icon" aria-hidden="true">✓</span><div><h2>Protection first</h2><p>Your activation key is never used as a password.</p></div></section>
    </div>
    <a class="brand-help" href="https://www.posprinteremulator.com/how-to-submit-a-support-request">Need help? Visit Support</a>
  </aside>

  <section class="auth-content" aria-labelledby="auth-title" data-auth-root data-initial-panel="<?= portal_e($activePanel) ?>">
    <?php if ($mfaPending): ?>
      <div class="auth-form-wrap compact">
        <h1 id="auth-title">Two-step sign-in</h1>
        <p>Enter the six-digit code from your authenticator app, or use one unused recovery code.</p>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= portal_e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
          <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
          <input type="hidden" name="action" value="mfa">
          <label>Authenticator or recovery code
            <input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
          </label>
          <button class="button primary" type="submit">Verify code</button>
        </form>
        <form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><button class="text-button" type="submit">Cancel and sign out</button></form>
      </div>
    <?php else: ?>
      <nav class="auth-tabs" aria-label="Account access">
        <a href="#sign-in" data-auth-mode="sign-in" aria-controls="sign-in">Sign in</a>
        <a href="#verify" data-auth-mode="verify" aria-controls="verify">Verify your email</a>
        <a href="#reset" data-auth-mode="reset" aria-controls="reset">Reset your password</a>
      </nav>
      <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= portal_e($error) ?></div><?php endif; ?>
      <?php if ($notice !== ''): ?><div class="alert success" role="status"><?= portal_e($notice) ?></div><?php endif; ?>
      <div class="auth-columns">
        <section id="sign-in" class="auth-primary" data-auth-panel="sign-in">
          <h1 id="auth-title">Sign in to your account</h1>
          <form method="post" class="form-stack">
            <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label>Email address
              <input type="email" name="email" maxlength="254" autocomplete="username" value="<?= portal_e($email) ?>" required>
            </label>
            <label>Password
              <input type="password" name="password" maxlength="200" autocomplete="current-password" required>
            </label>
            <button class="button primary" type="submit">Sign in</button>
          </form>
          <a class="inline-link" href="#reset" data-auth-mode="reset">Forgot password?</a>
          <p class="new-account">New here? <a href="#verify" data-auth-mode="verify">Verify your email</a> to create an account.</p>
          <p class="security-note">Your activation key is never used as a password.</p>
        </section>
        <div class="auth-secondary">
          <section id="verify" data-auth-panel="verify">
            <h2>Verify your customer record</h2>
            <p>Enter your email to receive a protected verification link.</p>
            <form method="post" action="/index.php#verify" class="form-stack small">
              <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
              <input type="hidden" name="action" value="enroll">
              <label>Email address<input type="email" name="email" maxlength="254" autocomplete="email" required></label>
              <button class="button secondary" type="submit">Send verification link</button>
            </form>
          </section>
          <section id="reset" data-auth-panel="reset">
            <h2>Reset your password</h2>
            <p>We use the same private response whether or not a record matches.</p>
            <form method="post" action="/index.php#reset" class="form-stack small">
              <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
              <input type="hidden" name="action" value="reset">
              <label>Email address<input type="email" name="email" maxlength="254" autocomplete="email" required></label>
              <button class="button secondary" type="submit">Send reset link</button>
            </form>
          </section>
        </div>
      </div>
    <?php endif; ?>
  </section>
</main>
<footer class="auth-footer"><span>© 2026 POS Printer Emulator.</span><nav><a href="https://www.posprinteremulator.com/privacy.html">Privacy</a><a href="https://www.posprinteremulator.com/how-to-submit-a-support-request">Support</a><a href="https://www.posprinteremulator.com/">Main website</a></nav></footer>
</body>
</html>
