<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';

$agent   = require_login();
$myEmail = strtolower(trim($agent['email'] ?? ''));

// offboard_queue has no unique constraint on agent_email — an agent could in
// theory have more than one 'active' row (e.g. re-added after a mistaken
// cancel). Pick the most recent one, same rule the API uses.
$st = local_db()->prepare(
    "SELECT * FROM offboard_queue WHERE LOWER(agent_email)=? AND status='active' ORDER BY added_at DESC LIMIT 1"
);
$st->execute([$myEmail]);
$activeCase = $st->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exit Interview — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('exit_interview', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Exit Interview</div>
        <div class="content-hello">Your feedback helps us improve — thank you for taking a few minutes</div>
      </header>
      <main class="wrap">
        <section class="card">
          <?php if (!$activeCase): ?>
          <p>You don't have an active offboarding case right now, so there's nothing to complete here.</p>
          <?php else: ?>
          <div id="ei-note" class="banner" hidden></div>
          <div id="submitted-badge" hidden></div>

          <form id="ei-form">
            <div class="form-grid">
              <div class="field full">
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

              <div class="field full">
                <label>Feedback on management/leadership</label>
                <textarea id="f-feedback_management" rows="3"></textarea>
              </div>

              <div class="field full">
                <label>Feedback on support staff</label>
                <textarea id="f-feedback_support" rows="3"></textarea>
              </div>

              <div class="field full">
                <label>Feedback on training</label>
                <textarea id="f-feedback_training" rows="3"></textarea>
              </div>

              <div class="field full">
                <label>Where are you headed next? <span style="font-weight:400;color:var(--faint)">(optional)</span></label>
                <input id="f-next_destination" type="text">
              </div>

              <div class="field full">
                <label>Would you recommend this brokerage to another agent?</label>
                <select id="f-would_recommend">
                  <option value="">Select one…</option>
                  <option value="yes">Yes</option>
                  <option value="maybe">Maybe</option>
                  <option value="no">No</option>
                </select>
              </div>

              <div class="field full">
                <label>Any other suggestions or feedback?</label>
                <textarea id="f-suggestions" rows="4"></textarea>
              </div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn-save" id="ei-submit-btn">Submit</button>
              <span class="form-msg" id="ei-msg"></span>
            </div>
          </form>
          <?php endif; ?>
        </section>
      </main>
    </div>
  </div>

  <?php if ($activeCase): ?>
  <script>
    function el(id) { return document.getElementById(id); }

    function showSubmitted(dateStr) {
      const badge = el('submitted-badge');
      const d = dateStr ? new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
      badge.innerHTML = '<span class="intake-submitted-badge">&#10003; Submitted' + (d ? ' on ' + d : '') + '</span>';
      badge.hidden = false;
      el('ei-form').hidden = true;
    }

    fetch('api/exit_interview.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) return;
        const ei = data.exit_interview || {};
        if (ei.satisfaction_rating) el('f-satisfaction_rating').value = ei.satisfaction_rating;
        el('f-feedback_management').value = ei.feedback_management || '';
        el('f-feedback_support').value    = ei.feedback_support    || '';
        el('f-feedback_training').value   = ei.feedback_training   || '';
        el('f-next_destination').value    = ei.next_destination    || '';
        if (ei.would_recommend) el('f-would_recommend').value = ei.would_recommend;
        el('f-suggestions').value = ei.suggestions || '';
        if (ei.submitted) showSubmitted(ei.submitted_at);
      });

    el('ei-form').addEventListener('submit', function(e) {
      e.preventDefault();

      const btn = el('ei-submit-btn');
      const msg = el('ei-msg');
      btn.disabled = true;
      msg.textContent = 'Submitting…';

      const payload = {
        satisfaction_rating: el('f-satisfaction_rating').value,
        feedback_management: el('f-feedback_management').value,
        feedback_support:    el('f-feedback_support').value,
        feedback_training:   el('f-feedback_training').value,
        next_destination:    el('f-next_destination').value,
        would_recommend:     el('f-would_recommend').value,
        suggestions:         el('f-suggestions').value,
        submitted: true
      };

      fetch('api/exit_interview.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function(r) { return r.json(); }).then(function(res) {
        btn.disabled = false;
        if (res.ok) {
          msg.textContent = '';
          showSubmitted(res.submitted_at);
        } else {
          msg.textContent = res.error || 'Submit failed.';
        }
      }).catch(function() {
        btn.disabled = false;
        msg.textContent = 'Network error.';
      });
    });
  </script>
  <?php endif; ?>
</body>
</html>
