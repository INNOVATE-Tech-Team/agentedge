<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$myEmail = strtolower(trim($agent['email'] ?? ''));
$myName  = $agent['name'] ?? '';

// Pull address from intake form
$intake = local_db()->prepare(
    "SELECT address_line1, address_line2, city, state, zip FROM agent_intake WHERE LOWER(TRIM(email))=? LIMIT 1"
);
$intake->execute([$myEmail]);
$addr = $intake->fetch(PDO::FETCH_ASSOC) ?: [];

// Check for a recent order (cooldown: 90 days)
$recent = local_db()->prepare(
    "SELECT ordered_at FROM swag_box_orders WHERE email=? AND ordered_at > datetime('now','-90 days') ORDER BY ordered_at DESC LIMIT 1"
);
$recent->execute([$myEmail]);
$lastOrder = $recent->fetchColumn();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recruiting Swag Box — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .swag-hero {
      background: linear-gradient(135deg, #f9fdf5 0%, #edf7d8 100%);
      border: 1px solid #c3dfa8; border-radius: 12px;
      padding: 28px 32px; margin-bottom: 24px;
      display: flex; align-items: flex-start; gap: 20px;
    }
    .swag-icon { font-size: 52px; line-height: 1; flex-shrink: 0; }
    .swag-hero-text h2 { margin: 0 0 8px; font-size: 20px; font-weight: 800; color: #1a1a1a; }
    .swag-hero-text p  { margin: 0; font-size: 14px; color: #555; line-height: 1.6; }

    .swag-card {
      background: #fff; border: 1px solid #e0e0e0; border-radius: 10px;
      padding: 24px 28px; margin-bottom: 20px;
    }
    .swag-card h3 { margin: 0 0 4px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #5b8e0d; }
    .swag-card p.card-sub { margin: 0 0 18px; font-size: 13px; color: #888; }

    .addr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .addr-grid .full { grid-column: 1 / -1; }
    .field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #888; margin-bottom: 4px; }
    .field input {
      width: 100%; padding: 9px 11px; border: 1px solid #ccc; border-radius: 6px;
      font-size: 13px; box-sizing: border-box; font-family: inherit;
    }
    .field input:focus { outline: 2px solid #82C112; border-color: #82C112; }

    .btn-order {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 12px 28px; background: #82C112; color: #000; border: none;
      border-radius: 8px; font-weight: 800; font-size: 15px; cursor: pointer;
      transition: background .15s;
    }
    .btn-order:hover:not(:disabled) { background: #5b8e0d; color: #fff; }
    .btn-order:disabled { opacity: .55; cursor: not-allowed; }

    .swag-msg { font-size: 13px; margin-top: 14px; min-height: 20px; }
    .swag-msg.ok    { color: #2d7a1e; font-weight: 700; }
    .swag-msg.error { color: #c0392b; }

    .cooldown-notice {
      background: #fffbf0; border: 1px solid #f0d060; border-radius: 10px;
      padding: 18px 22px; font-size: 14px; color: #7a5a00; line-height: 1.6;
    }
    .cooldown-notice strong { color: #5a3a00; }

    @media (max-width: 560px) {
      .swag-hero { flex-direction: column; gap: 12px; padding: 20px; }
      .addr-grid { grid-template-columns: 1fr; }
      .addr-grid .full { grid-column: 1; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('swag_box', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Recruiting Swag Box</div>
      <div class="content-hello">Order a swag kit to share with your recruiting prospects</div>
    </header>
    <main class="wrap">

      <div class="swag-hero">
        <div class="swag-icon">&#127873;</div>
        <div class="swag-hero-text">
          <h2>Request Your Recruiting Swag Box</h2>
          <p>We'll ship a branded INNOVATE swag kit straight to you — ready to hand out to agents you're recruiting.
             Confirm your shipping address below and hit the button. Lisa and Kelsey will take it from there.</p>
        </div>
      </div>

      <?php if ($lastOrder): ?>
        <div class="cooldown-notice">
          <strong>You already requested a swag box recently.</strong><br>
          Your last order was on <?= h(date('F j, Y', strtotime($lastOrder))) ?>.
          Please wait 90 days between orders — if you need more sooner, email
          <a href="mailto:lisa@innovateonline.com" style="color:#5b8e0d">lisa@innovateonline.com</a> directly.
        </div>
      <?php else: ?>

      <div class="swag-card">
        <h3>Shipping Address</h3>
        <p class="card-sub">We'll ship to the address below. Edit it here if anything has changed — this won't update your profile.</p>

        <form id="swag-form" onsubmit="submitOrder(event)">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <div class="addr-grid">
            <div class="field full">
              <label>Address Line 1</label>
              <input id="f-address_line1" name="address_line1" type="text"
                     value="<?= h($addr['address_line1'] ?? '') ?>" required placeholder="Street address">
            </div>
            <div class="field full">
              <label>Address Line 2 <small style="font-weight:400;color:#bbb">(optional)</small></label>
              <input id="f-address_line2" name="address_line2" type="text"
                     value="<?= h($addr['address_line2'] ?? '') ?>" placeholder="Apt, suite, unit…">
            </div>
            <div class="field">
              <label>City</label>
              <input id="f-city" name="city" type="text"
                     value="<?= h($addr['city'] ?? '') ?>" required placeholder="City">
            </div>
            <div class="field">
              <label>State</label>
              <input id="f-state" name="state" type="text"
                     value="<?= h($addr['state'] ?? '') ?>" required placeholder="SC" maxlength="2" style="text-transform:uppercase">
            </div>
            <div class="field">
              <label>ZIP Code</label>
              <input id="f-zip" name="zip" type="text"
                     value="<?= h($addr['zip'] ?? '') ?>" required placeholder="29576" maxlength="10">
            </div>
          </div>

          <div style="margin-top:20px">
            <button type="submit" class="btn-order" id="btn-order">
              <span>&#128230;</span> Request My Swag Box
            </button>
            <div class="swag-msg" id="swag-msg"></div>
          </div>
        </form>
      </div>

      <?php endif; ?>

    </main>
  </div>
</div>
<script>
async function submitOrder(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-order');
  const msg = document.getElementById('swag-msg');
  const form = document.getElementById('swag-form');
  btn.disabled = true;
  btn.textContent = 'Sending…';
  msg.className = 'swag-msg';
  msg.textContent = '';

  const data = {
    csrf:          form.querySelector('[name=csrf]').value,
    address_line1: form.querySelector('[name=address_line1]').value.trim(),
    address_line2: form.querySelector('[name=address_line2]').value.trim(),
    city:          form.querySelector('[name=city]').value.trim(),
    state:         form.querySelector('[name=state]').value.trim().toUpperCase(),
    zip:           form.querySelector('[name=zip]').value.trim(),
  };

  try {
    const res = await fetch('api/swag_box_order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const json = await res.json();
    if (json.ok) {
      msg.className = 'swag-msg ok';
      msg.textContent = 'Request sent! Lisa and Kelsey will get your box out to you shortly.';
      btn.textContent = 'Request Sent';
      form.querySelectorAll('input').forEach(i => i.disabled = true);
    } else {
      msg.className = 'swag-msg error';
      msg.textContent = json.error || 'Something went wrong. Please try again.';
      btn.disabled = false;
      btn.innerHTML = '<span>&#128230;</span> Request My Swag Box';
    }
  } catch {
    msg.className = 'swag-msg error';
    msg.textContent = 'Network error. Please try again.';
    btn.disabled = false;
    btn.innerHTML = '<span>&#128230;</span> Request My Swag Box';
  }
}
</script>
</body>
</html>
