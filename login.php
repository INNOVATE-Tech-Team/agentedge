<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$c = cfg();
$google_url = null;
if (!empty($c['google_client_id'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    $google_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $c['google_client_id'],
        'redirect_uri'  => !empty($c['google_redirect_uri']) ? $c['google_redirect_uri'] : ('https://' . $_SERVER['HTTP_HOST'] . '/auth_google.php'),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

$err = null;
$google_err_map = [
    'state_mismatch'        => 'Sign-in failed (state mismatch). Please try again.',
    'token_exchange_failed' => 'Google sign-in failed. Please try again.',
    'account_disabled'      => 'Your account has been disabled. Contact your administrator.',
    'user_create_failed'    => 'Could not create your account. Contact your administrator.',
    'access_denied'         => 'Google sign-in was cancelled.',
];
if (!empty($_GET['google_err'])) {
    $err = $google_err_map[$_GET['google_err']] ?? 'Google sign-in failed. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent = attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($agent) {
        session_regenerate_id(true);
        $_SESSION['agent'] = $agent;
        log_login_event($agent['email'], $agent['name'] ?? '', 'password');
        header('Location: index.php');
        exit;
    }
    $err = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge — Sign in</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-body">
  <div class="login-card">
    <div class="brand">INNOVATE</div>
    <div class="brand-sub">AgentEdge</div>

    <?php if ($err): ?>
      <div class="login-err"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if ($google_url): ?>
      <a class="btn-google" href="<?= htmlspecialchars($google_url) ?>">
        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"/>
          <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
          <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/>
          <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
        </svg>
        Sign in with Google
      </a>
      <div class="login-divider"><span>or</span></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <label>Email
        <input type="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit">Sign in</button>
      <a class="login-forgot" href="forgot_password.php">Forgot password?</a>
    </form>
  </div>
</body>
</html>
