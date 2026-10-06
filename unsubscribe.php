<?php
// Public one-click Company Email unsubscribe — no login required, reached
// only via the unguessable per-email token minted by ce_unsubscribe_url()
// (lib/company_email.php). Sets notification_prefs.notify_email=0, which
// every Company Email audience already checks before sending (see
// ce_resolve_single_audience()) — no separate suppression list needed.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/local_db.php';

$email = strtolower(trim($_GET['email'] ?? ''));
$token = trim($_GET['t'] ?? '');
$ok  = false;
$err = '';

if ($email === '' || $token === '') {
    $err = 'This unsubscribe link is missing information and can\'t be processed.';
} else {
    $db = local_db();
    $stmt = $db->prepare("SELECT unsub_token FROM notification_prefs WHERE email=?");
    $stmt->execute([$email]);
    $stored = $stmt->fetchColumn();
    if ($stored === false || $stored === '' || !hash_equals((string)$stored, $token)) {
        $err = 'This unsubscribe link is invalid or has expired.';
    } else {
        $db->prepare(
            "INSERT INTO notification_prefs (email, notify_email, unsub_token) VALUES (?, 0, ?)
             ON CONFLICT(email) DO UPDATE SET notify_email = 0, updated_at = datetime('now')"
        )->execute([$email, $token]);
        $ok = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unsubscribe — INNOVATE</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6f8; color: #222; }
  .wrap { max-width: 440px; margin: 0 auto; padding: 60px 20px; text-align: center; }
  .brand { font-size: 12px; font-weight: 800; color: #5b8e0d; letter-spacing: .04em; margin-bottom: 16px; }
  .card { background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; padding: 32px 28px; }
  h1 { font-size: 18px; margin: 0 0 10px; }
  p { font-size: 14px; color: #555; line-height: 1.6; margin: 0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">INNOVATE</div>
  <div class="card">
    <?php if ($ok): ?>
      <h1>You're unsubscribed</h1>
      <p><?= htmlspecialchars($email, ENT_QUOTES) ?> won't receive Company Email broadcasts going forward. If this was a mistake, contact your Market Center Leader to be re-added.</p>
    <?php else: ?>
      <h1>Couldn't process this request</h1>
      <p><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
