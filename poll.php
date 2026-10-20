<?php
// Agent-facing poll runner: /poll.php?slug=<slug>. Renders the poll's
// definition (never anyone's results) and hands it to assets/polls.js.
// Submission and results go through api/polls_action.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/polls.php';

$agent = require_login();
$pdo   = local_db();
$poll  = polls_load($pdo, trim((string)($_GET['slug'] ?? '')));
if (!$poll) { header('Location: ' . (poll_viewer_is_super_admin() ? 'polls.php' : 'index.php')); exit; }

// "Super admin" for preview purposes includes an admin currently logged in as
// an agent (masquerading swaps the session's agent, so is_super_admin() alone
// would read the agent's role).
function poll_viewer_is_super_admin(): bool {
    if (function_exists('is_masquerading') && is_masquerading()) {
        $orig = original_admin();
        return $orig && !empty(fetch_perms((string)($orig['email'] ?? ''))['isSuperAdmin']);
    }
    return is_super_admin();
}

$identity = polls_identity($pdo, $agent);
$mine     = $identity ? polls_find_response($pdo, $poll['id'], $identity) : null;
$isOpen   = polls_is_open($poll);
$superAdm = poll_viewer_is_super_admin();
$masq     = function_exists('is_masquerading') && is_masquerading();

// Who may see this page at all.
$closedNotice = false;
if ($poll['status'] === 'draft' && !$superAdm) { header('Location: index.php'); exit; }
$preview = false; $previewWhy = '';
if (!$mine) {
    if ($masq)                  { $preview = true; $previewWhy = 'You are logged in as another user.'; }
    elseif (!$isOpen && $superAdm) { $preview = true; $previewWhy = 'This poll is ' . ($poll['status'] === 'open' ? 'outside its open window' : $poll['status']) . '.'; }
    elseif (!$isOpen)           { $closedNotice = true; }
}
if (!$identity && !$preview) { $closedNotice = true; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$cfg = [
    'slug' => $poll['slug'], 'billUrl' => 'assets/innovate-dollar.png',
    'header' => $poll['config']['header'] ?? $poll['title'], 'eyebrow' => $poll['config']['eyebrow'] ?? '',
    'thanksEyebrow' => $poll['config']['thanks_eyebrow'] ?? $poll['title'], 'footer' => $poll['config']['footer'] ?? '',
    'me' => $identity['display_name'] ?? (string)($agent['name'] ?? ''),
    'voted' => (bool)$mine, 'myAnswers' => $mine ? $mine['answers'] : null,
    'preview' => $preview, 'previewWhy' => $previewWhy,
    'steps' => array_map(fn($s) => [
        'key' => $s['step_key'], 'type' => $s['type'], 'title' => $s['title'], 'body' => $s['body'],
        'pill' => $s['config']['pill'] ?? $s['title'], 'notice' => $s['config']['notice'] ?? '',
        'bills' => (int)($s['config']['bills'] ?? 0), 'value' => (int)($s['config']['value'] ?? 0),
        'options' => array_map(fn($o) => ['key' => $o['opt_key'], 'label' => $o['label'], 'description' => $o['description']], $s['options']),
    ], $poll['steps']),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($poll['title']) ?> — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="assets/polls.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('polls', $agent); ?>
  <div class="content">
    <header class="content-top"><div class="content-title"><?= h($poll['title']) ?></div></header>
    <main class="wrap poll-main">
    <?php if ($closedNotice): ?>
      <div class="poll-stage" id="poll-stage"><div class="poll-inner">
        <h1 class="poll-h1" style="font-size:40px">This poll is closed.</h1>
        <p class="poll-lede">Thanks for stopping by. <a href="index.php">Back to Agent Edge</a></p>
      </div></div>
    <?php else: ?>
      <div class="poll-stage" id="poll-stage"><div class="poll-inner" id="poll-root"></div></div>
      <script>window.POLL_CFG = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
      <script src="assets/polls.js"></script>
    <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
