<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge — Reset your password</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-body">
  <div class="login-card">
    <a href="index.php" class="login-logo"><img src="assets/logo.png" alt="INNOVATE Real Estate"></a>

    <p class="login-hint">Enter your email and we'll send you a link to set a new password.</p>

    <div id="fp-msg" style="display:none;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px"></div>

    <form id="fp-form">
      <label>Email
        <input type="email" name="email" id="fp-email" required autofocus>
      </label>
      <button type="submit" id="fp-btn">Send reset link</button>
      <a class="login-forgot" href="login.php">Back to sign in</a>
    </form>
  </div>
  <div class="login-footer">
    &copy; <?= date('Y') ?> Copyright INNOVATE Real Estate &middot; <a href="privacy.php">Privacy Policy</a>
  </div>

  <script>
    document.getElementById('fp-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = document.getElementById('fp-btn');
      var msg = document.getElementById('fp-msg');
      btn.disabled = true;
      fetch('api/password_reset.php?action=request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: document.getElementById('fp-email').value })
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          msg.style.display = 'block';
          msg.style.background = '#eef5e8';
          msg.style.color = '#3a6b1a';
          msg.textContent = res.message || 'If that email has an AgentEdge account, a reset link is on its way.';
          document.getElementById('fp-form').querySelector('input').value = '';
        })
        .catch(function () {
          btn.disabled = false;
          msg.style.display = 'block';
          msg.style.background = '#fff0f0';
          msg.style.color = '#c00';
          msg.textContent = 'Network error — please try again.';
        });
    });
  </script>
</body>
</html>
