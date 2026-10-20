<?php
// Agent-facing list of open polls, with the ones the agent already completed.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/polls.php';

$agent    = require_login();
$pdo      = local_db();
$identity = polls_identity($pdo, $agent);
// While logged in as another user, current_perms() is that user's, so this is
// false and the list shows exactly what that agent would see.
$isSuper  = is_super_admin();
// Polls are direct-link-only for agents (poll.php?slug=...): no browsable
// directory. Only a super admin may list polls here.
if (!$isSuper) { header('Location: index.php'); exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$items = [];
foreach ($pdo->query("SELECT slug FROM polls ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
    $p = polls_load($pdo, $slug);
    if (!$p) continue;
    $done = $identity && polls_find_response($pdo, $p['id'], $identity);
    $open = polls_is_open($p);
    // Agents see open polls, plus closed ones they took part in. Drafts and
    // other closed polls are invisible to them. Only a real super admin sees
    // those, flagged so it's obvious they're not live.
    if (!$open && !$done && !$isSuper) continue;
    $items[] = ['poll' => $p, 'done' => (bool)$done, 'open' => $open];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Polls — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="assets/polls.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('polls', $agent); ?>
  <div class="content">
    <header class="content-top"><div class="content-title">Polls</div></header>
    <main class="wrap">
      <div class="polls-list">
        <?php if (!$items): ?>
          <div style="text-align:center;padding:48px 0;color:var(--faint)">No polls are open right now. Check back soon.</div>
        <?php endif; ?>
        <?php foreach ($items as $it): $p = $it['poll']; ?>
          <a class="polls-item" href="poll.php?slug=<?= urlencode($p['slug']) ?>">
            <div><h3><?= h($p['title']) ?></h3><p><?= h($p['subtitle']) ?></p></div>
            <?php if ($it['done']): ?><span class="polls-badge">Completed ✓</span>
            <?php elseif ($it['open']): ?><span class="polls-badge todo">Take the poll</span>
            <?php else: ?><span class="polls-badge draft"><?= h(ucfirst($p['status'])) ?> · preview</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
