<?php
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/notifications.php';

$qid   = (int)($_GET['qid'] ?? 0);
$token = (string)($_GET['t'] ?? '');

$invalid = true;
$entry   = null;
if ($qid > 0 && $token !== '' && hash_equals(exit_interview_link_token($qid), $token)) {
    $st = local_db()->prepare("SELECT id, agent_name, agent_email, status FROM offboard_queue WHERE id=?");
    $st->execute([$qid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $entry  = $row;
        $invalid = false;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exit Interview — AgentEdge</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f5f6; min-height: 100vh; }
    .brand-header { background: #111; padding: 16px 24px; display: flex; align-items: center; gap: 12px; }
    .brand-logo { color: #fff; font-size: 15px; font-weight: 800; letter-spacing: .04em; text-decoration: none; }
    .brand-logo span { color: #7EC8E3; }
    .wrap { max-width: 680px; margin: 32px auto; padding: 0 16px 48px; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 28px 28px 32px; }
    h1 { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
    .subtitle { font-size: 14px; color: #666; margin-bottom: 24px; }
    label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 5px; }
    label .opt { font-weight: 400; color: #999; }
    select, textarea, input[type=text] {
      width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px;
      font-size: 14px; font-family: inherit; color: #222; outline: none;
      transition: border-color .15s;
    }
    select:focus, textarea:focus, input[type=text]:focus { border-color: #7EC8E3; }
    textarea { resize: vertical; }
    .field { margin-bottom: 18px; }
    .btn-submit {
      background: #1a1a2e; color: #fff; border: none; border-radius: 6px;
      padding: 11px 26px; font-size: 14px; font-weight: 600; cursor: pointer;
      transition: opacity .15s;
    }
    .btn-submit:disabled { opacity: .6; cursor: default; }
    .form-msg { font-size: 13px; color: #c0392b; margin-left: 12px; }
    .submitted-badge {
      display: inline-flex; align-items: center; gap: 6px; background: #e8f5e9;
      color: #2e7d32; border-radius: 20px; padding: 6px 14px; font-size: 13px;
      font-weight: 600; margin-bottom: 18px;
    }
    .error-box { padding: 24px; text-align: center; color: #555; }
    .error-box strong { display: block; font-size: 18px; color: #c0392b; margin-bottom: 8px; }
  </style>
</head>
<body>
  <header class="brand-header">
    <div class="brand-logo">INNOVATE <span>AgentEdge</span></div>
  </header>
  <div class="wrap">
    <div class="card">
      <?php if ($invalid): ?>
        <div class="error-box">
          <strong>Link invalid or expired</strong>
          This exit interview link is not valid. If you believe this is an error, please contact your brokerage office.
        </div>
      <?php else: ?>
        <h1>Exit Interview</h1>
        <p class="subtitle">Hi <?= htmlspecialchars($entry['agent_name']) ?> — your feedback helps us improve. Thank you for taking a few minutes.</p>

        <div id="submitted-badge-wrap" style="display:none">
          <div class="submitted-badge">&#10003; Submitted — thank you!</div>
        </div>

        <div id="ei-note" style="display:none;color:#c0392b;margin-bottom:12px;font-size:13px"></div>

        <form id="ei-form">
          <div class="field">
            <label>Overall, how satisfied were you with your time here?</label>
            <select id="f-satisfaction_rating" required>
              <option value="">Select one…</option>
              <option value="5">5 — Very satisfied</option>
              <option value="4">4 — Satisfied</option>
              <option value="3">3 — Neutral</option>
              <option value="2">2 — Dissatisfied</option>
              <option value="1">1 — Very dissatisfied</option>
            </select>
          </div>
          <div class="field">
            <label>Feedback on management/leadership</label>
            <textarea id="f-feedback_management" rows="3"></textarea>
          </div>
          <div class="field">
            <label>Feedback on support staff</label>
            <textarea id="f-feedback_support" rows="3"></textarea>
          </div>
          <div class="field">
            <label>Feedback on training</label>
            <textarea id="f-feedback_training" rows="3"></textarea>
          </div>
          <div class="field">
            <label>Where are you headed next? <span class="opt">(optional)</span></label>
            <input id="f-next_destination" type="text">
          </div>
          <div class="field">
            <label>Would you recommend this brokerage to another agent?</label>
            <select id="f-would_recommend">
              <option value="">Select one…</option>
              <option value="yes">Yes</option>
              <option value="maybe">Maybe</option>
              <option value="no">No</option>
            </select>
          </div>
          <div class="field">
            <label>Any other suggestions or feedback? <span class="opt">(optional)</span></label>
            <textarea id="f-suggestions" rows="4"></textarea>
          </div>
          <div>
            <button type="submit" class="btn-submit" id="ei-submit-btn">Submit</button>
            <span class="form-msg" id="ei-msg"></span>
          </div>
        </form>

        <script>
          var QID   = <?= (int)$entry['id'] ?>;
          var TOKEN = <?= json_encode($token) ?>;
          var API   = 'api/public_exit_interview.php?qid=' + QID + '&t=' + encodeURIComponent(TOKEN);

          function el(id) { return document.getElementById(id); }

          function showSubmitted() {
            el('submitted-badge-wrap').style.display = 'block';
            el('ei-form').style.display = 'none';
          }

          fetch(API)
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (!data.ok) return;
              var ei = data.exit_interview || {};
              if (ei.satisfaction_rating) el('f-satisfaction_rating').value = ei.satisfaction_rating;
              el('f-feedback_management').value = ei.feedback_management || '';
              el('f-feedback_support').value    = ei.feedback_support    || '';
              el('f-feedback_training').value   = ei.feedback_training   || '';
              el('f-next_destination').value    = ei.next_destination    || '';
              if (ei.would_recommend) el('f-would_recommend').value = ei.would_recommend;
              el('f-suggestions').value = ei.suggestions || '';
              if (ei.submitted) showSubmitted();
            });

          el('ei-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = el('ei-submit-btn');
            var msg = el('ei-msg');
            btn.disabled = true;
            msg.textContent = 'Submitting…';

            var payload = {
              satisfaction_rating: el('f-satisfaction_rating').value,
              feedback_management: el('f-feedback_management').value,
              feedback_support:    el('f-feedback_support').value,
              feedback_training:   el('f-feedback_training').value,
              next_destination:    el('f-next_destination').value,
              would_recommend:     el('f-would_recommend').value,
              suggestions:         el('f-suggestions').value,
              submitted: true
            };

            fetch(API, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            }).then(function(r) { return r.json(); }).then(function(res) {
              btn.disabled = false;
              if (res.ok) {
                msg.textContent = '';
                showSubmitted();
              } else {
                msg.textContent = res.error || 'Submit failed.';
              }
            }).catch(function() {
              btn.disabled = false;
              msg.textContent = 'Network error — please try again.';
            });
          });
        </script>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
