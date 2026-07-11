<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$token = trim($_GET['token'] ?? '');
if ($token === '') { header('Location: forgot_password.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge — Set a new password</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-body">
  <div class="login-card">
    <div class="brand">INNOVATE</div>
    <div class="brand-sub">AgentEdge</div>

    <p class="login-hint">Choose a new password (at least 8 characters).</p>

    <div id="rp-msg" style="display:none;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px"></div>

    <form id="rp-form">
      <label>New Password
        <input type="password" name="new_password" id="rp-new" required minlength="8">
      </label>
      <label>Confirm Password
        <input type="password" name="confirm_password" id="rp-confirm" required minlength="8">
      </label>
      <button type="submit" id="rp-btn">Set password &amp; sign in</button>
      <a class="login-forgot" href="login.php">Back to sign in</a>
    </form>
  </div>

  <script>
    var TOKEN = <?= json_encode($token) ?>;
    document.getElementById('rp-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = document.getElementById('rp-btn');
      var msg = document.getElementById('rp-msg');
      btn.disabled = true;
      fetch('api/password_reset.php?action=confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: TOKEN,
          new_password: document.getElementById('rp-new').value,
          confirm_password: document.getElementById('rp-confirm').value
        })
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            window.location.href = 'index.php';
            return;
          }
          btn.disabled = false;
          msg.style.display = 'block';
          msg.style.background = '#fff0f0';
          msg.style.color = '#c00';
          msg.textContent = res.error || 'Something went wrong.';
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
