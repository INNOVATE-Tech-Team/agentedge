<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/disc-db.php';

$agent = current_agent();
$err   = '';
$state = 'intro';

// Logged-in agents skip the name/email form
if ($agent) {
    $_SESSION['disc_who'] = [
        'name'     => $agent['name'],
        'email'    => $agent['email'],
        'agent_id' => $agent['id'],
    ];
    $state = 'questions';
}

// Intro form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disc_start'])) {
    $name  = trim(strip_tags($_POST['name']  ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim(strip_tags($_POST['phone'] ?? ''));
    $role  = trim(strip_tags($_POST['role']  ?? ''));
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter your full name and a valid email address.';
    } else {
        $_SESSION['disc_who'] = [
            'name'     => $name,
            'email'    => $email,
            'phone'    => $phone,
            'role'     => $role,
            'agent_id' => null,
        ];
        $state = 'questions';
    }
}

// Session already has identity from a prior intro submission
if ($state === 'intro' && !empty($_SESSION['disc_who'])) {
    $state = 'questions';
}

// Assessment submission POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disc_submit'])) {
    $who = $_SESSION['disc_who'] ?? null;
    if (!$who) {
        $state = 'intro';
    } else {
        $keys   = ['D','I','S','C'];
        $scores = ['D'=>0,'I'=>0,'S'=>0,'C'=>0];
        $answers = [];
        $ok = true;
        $total = count(DISC_QUESTIONS);
        for ($i = 0; $i < $total; $i++) {
            $v = $_POST['q'.$i] ?? '';
            if (!in_array($v, ['0','1','2','3'], true)) { $ok = false; break; }
            $idx = (int)$v;
            $answers[] = $idx;
            $scores[$keys[$idx]]++;
        }
        if (!$ok) {
            $err   = 'Please answer all 24 questions before submitting.';
            $state = 'questions';
        } else {
            $sorted = $scores; arsort($sorted); $order = array_keys($sorted);
            $primary = $order[0];
            $second  = ($scores[$order[1]] >= $scores[$order[0]] * 0.6) ? $order[1] : null;
            $token = disc_save([
                'agent_id'        => $who['agent_id'],
                'name'            => $who['name'],
                'email'           => $who['email'],
                'phone'           => $who['phone'] ?? '',
                'role'            => $who['role']  ?? '',
                'answers'         => $answers,
                'score_d'         => $scores['D'],
                'score_i'         => $scores['I'],
                'score_s'         => $scores['S'],
                'score_c'         => $scores['C'],
                'primary_style'   => $primary,
                'secondary_style' => $second,
            ]);
            $saved = disc_by_token($token);
            if ($saved) disc_send_email($saved);
            unset($_SESSION['disc_who'], $_SESSION['disc_shuffle']);
            header('Location: disc-result.php?token=' . urlencode($token));
            exit;
        }
    }
}

$questions = DISC_QUESTIONS;
$total_q   = count($questions);

// Generate a per-session shuffle so option order varies per question.
// Radio values stay as the original DISC index (0=D,1=I,2=S,3=C)
// so scoring is unaffected by display order.
if ($state === 'questions' && empty($_SESSION['disc_shuffle'])) {
    $shuffle = [];
    for ($i = 0; $i < $total_q; $i++) {
        $perm = [0, 1, 2, 3];
        shuffle($perm);
        $shuffle[] = $perm;
    }
    $_SESSION['disc_shuffle'] = $shuffle;
}
$shuffle = $_SESSION['disc_shuffle'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DISC Assessment — INNOVATE AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span>
  </div>
  <div class="topbar-right">
    <?php if ($agent): ?>
      <span class="who"><?= htmlspecialchars($agent['name']) ?></span>
      <a class="logout" href="index.php">Dashboard</a>
      <a class="logout" href="logout.php">Sign out</a>
    <?php else: ?>
      <a class="logout" href="login.php">Sign in</a>
    <?php endif; ?>
  </div>
</header>

<div class="disc-wrap">

  <?php if ($err): ?>
    <div class="banner" style="margin-bottom:16px"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <?php if ($state === 'intro'): ?>
  <!-- ─── INTRO ─────────────────────────────────────── -->
  <div class="disc-intro">
    <div class="disc-intro-icon">DISC</div>
    <h1>Personality Assessment</h1>
    <p>The DISC model helps you understand your natural communication and work style — and how to connect better with clients, teammates, and prospects.</p>
    <p><strong>24 questions &bull; about 5 minutes.</strong> For each question, choose the response that feels most natural to you. There are no right or wrong answers.</p>
    <form method="post" class="disc-intro-form">
      <div class="disc-intro-row">
        <label class="disc-field-label">Full Name
          <input type="text" name="name" class="disc-field" placeholder="Jane Smith" required autocomplete="name">
        </label>
        <label class="disc-field-label">Email Address
          <input type="email" name="email" class="disc-field" placeholder="jane@example.com" required autocomplete="email">
        </label>
      </div>
      <div class="disc-intro-row">
        <label class="disc-field-label">Phone Number
          <input type="tel" name="phone" class="disc-field" placeholder="(843) 555-0100" autocomplete="tel">
        </label>
        <label class="disc-field-label">Your Role
          <select name="role" class="disc-field disc-select">
            <option value="">Select one&hellip;</option>
            <option value="Current Agent">Current Agent</option>
            <option value="Prospective Agent">Prospective Agent</option>
            <option value="Team Leader / BIC">Team Leader / BIC</option>
            <option value="Staff / Admin">Staff / Admin</option>
            <option value="Other">Other</option>
          </select>
        </label>
      </div>
      <button type="submit" name="disc_start" class="disc-btn">Start Assessment &rarr;</button>
    </form>
  </div>

  <?php else: ?>
  <!-- ─── QUESTIONS ─────────────────────────────────── -->
  <div class="disc-qs-header">
    <div class="disc-qs-title">DISC Assessment</div>
    <div class="disc-qs-sub">For each question, choose the response that feels most natural to you. There are no right or wrong answers — answer all 24.</div>
    <div class="disc-progress">
      <div class="disc-progress-bar" id="prog-bar" style="width:0%"></div>
    </div>
    <div class="disc-prog-text" id="prog-text">0 of <?= $total_q ?> answered</div>
  </div>

  <form method="post" id="disc-form">
    <div class="disc-form">
      <?php foreach ($questions as $qi => $q):
            $perm = $shuffle ? $shuffle[$qi] : [0,1,2,3]; ?>
        <div class="disc-q" id="dq<?= $qi ?>">
          <div class="disc-q-num">Question <?= $qi + 1 ?> of <?= $total_q ?></div>
          <div class="disc-q-text"><?= htmlspecialchars($q['q']) ?></div>
          <div class="disc-opts">
            <?php foreach ($perm as $display_pos => $orig_idx): ?>
              <div class="disc-opt">
                <input type="radio" name="q<?= $qi ?>" id="q<?= $qi ?>o<?= $display_pos ?>"
                       value="<?= $orig_idx ?>" onchange="onAnswer(<?= $qi ?>)">
                <label for="q<?= $qi ?>o<?= $display_pos ?>"><?= htmlspecialchars($q['opts'][$orig_idx]) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="disc-submit-wrap">
      <p class="disc-submit-hint" id="submit-hint"></p>
      <button type="submit" name="disc_submit" class="disc-btn">See My Results &rarr;</button>
    </div>
  </form>

  <script>
  var answered = 0, total = <?= $total_q ?>;

  function onAnswer(qi) {
    var prev = document.querySelector('#dq'+qi+'.disc-q-done');
    if (!prev) {
      document.getElementById('dq'+qi).classList.add('disc-q-done');
      answered++;
      var pct = Math.round(answered / total * 100);
      document.getElementById('prog-bar').style.width = pct + '%';
      document.getElementById('prog-text').textContent = answered + ' of ' + total + ' answered';
    }
  }

  document.getElementById('disc-form').addEventListener('submit', function(e) {
    for (var i = 0; i < total; i++) {
      if (!document.querySelector('input[name="q'+i+'"]:checked')) {
        e.preventDefault();
        var el = document.getElementById('dq'+i);
        el.scrollIntoView({behavior:'smooth', block:'center'});
        el.classList.add('disc-q-error');
        setTimeout(function(){ el.classList.remove('disc-q-error'); }, 2500);
        document.getElementById('submit-hint').textContent =
          'Scroll up — you have unanswered questions highlighted in red.';
        return;
      }
    }
  });
  </script>
  <?php endif; ?>

</div>
</body>
</html>
