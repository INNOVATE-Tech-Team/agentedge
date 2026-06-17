<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$err = null;

// Map OAuth error codes to friendly messages
$oauthErrors = [
    'not_in_roster'        => 'Your Google account is not in the INNOVATE agent roster. Contact your Market Center Leader.',
    'oauth_state'          => 'Sign-in session expired. Please try again.',
    'oauth_token'          => 'Could not complete Google sign-in. Please try again.',
    'google_not_configured'=> 'Google sign-in is not configured yet.',
];
if (isset($_GET['err']) && isset($oauthErrors[$_GET['err']])) {
    $err = $oauthErrors[$_GET['err']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent = attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($agent) {
        session_regenerate_id(true);
        $_SESSION['agent'] = $agent;
        header('Location: index.php');
        exit;
    }
    $err = 'Invalid email or password.';
}

$reset_url    = cfg()['reset_url']      ?? 'https://agents.innovateonline.com/admin/authentication/forgot_password';
$googleClient = cfg()['google_client_id'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge — Sign in</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .login-or{display:flex;align-items:center;gap:12px;margin:20px 0;color:#aaa;font-size:12px}
    .login-or::before,.login-or::after{content:'';flex:1;height:1px;background:#e0e0e0}
    .btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:11px 16px;border:1px solid #dadce0;border-radius:6px;background:white;font-size:14px;font-weight:500;color:#3c4043;cursor:pointer;text-decoration:none;transition:background 120ms}
    .btn-google:hover{background:#f8f9fa;border-color:#c8ccd0}
    .btn-google svg{flex-shrink:0}
    .login-hint{font-size:13px;color:#666;margin:0 0 20px;text-align:center}
  </style>
</head>
<body class="login-body">
  <div class="login-card">
    <div class="brand">INNOVATE</div>
    <div class="brand-sub">AgentEdge</div>

    <?php if ($err): ?>
      <div class="login-err"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if ($googleClient !== ''): ?>
      <!-- Google Sign-In — primary method for all agents -->
      <a class="btn-google" href="auth_google.php?start=1">
        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
          <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
          <path d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
          <path d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.548 0 9s.348 2.825.957 4.039l3.007-2.332z" fill="#FBBC05"/>
          <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z" fill="#EA4335"/>
        </svg>
        Sign in with Google
      </a>

      <div class="login-or">or use your back-office password</div>
    <?php else: ?>
      <p class="login-hint">Sign in with your INNOVATE account.</p>
    <?php endif; ?>

    <!-- Email + password fallback -->
    <form method="post" autocomplete="on">
      <label>Email
        <input type="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit">Sign in</button>
      <a class="login-forgot" href="<?= htmlspecialchars($reset_url) ?>" target="_blank" rel="noopener">Forgot password?</a>
      <p class="login-note">Your password is the same as your INNOVATE back-office login.</p>
    </form>
  </div>
</body>
</html>
