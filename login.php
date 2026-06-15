<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$err = null;
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

// Where "Forgot password?" sends agents. Resets must happen where the password
// actually lives (the Perfex back office), so the two never get out of sync.
$reset_url = cfg()['reset_url'] ?? '';
if ($reset_url === '') $reset_url = 'https://agents.innovateonline.com/admin/authentication/forgot_password';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge — Sign in</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" autocomplete="on">
    <div class="brand">INNOVATE</div>
    <div class="brand-sub">AgentEdge</div>
    <p class="login-hint">Sign in with your INNOVATE account.</p>

    <?php if ($err): ?><div class="login-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <label>Email
      <input type="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit">Sign in</button>
    <a class="login-forgot" href="<?= htmlspecialchars($reset_url) ?>" target="_blank" rel="noopener">Forgot password?</a>
    <p class="login-note">Your AgentEdge password is the same as your INNOVATE back-office password.</p>
  </form>
</body>
</html>
