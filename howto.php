<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/markdown.php';
$agent = require_login();

$key = trim($_GET['key'] ?? '');
$db = local_db();
$article = null;
if ($key !== '') {
    $st = $db->prepare("SELECT * FROM howto_articles WHERE page_key = ? AND is_stale = 0");
    $st->execute([$key]);
    $article = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// No key (or a key that no longer resolves) -- show the browsable index instead.
$allArticles = [];
if (!$article) {
    $allArticles = $db->query(
        "SELECT page_key, label, href FROM howto_articles WHERE is_stale = 0 ORDER BY label"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $article ? htmlspecialchars($article['label']) . ' — How-To' : 'How-To Library' ?> — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .howto-back{font-size:12px;color:#5b8e0d;text-decoration:none;font-weight:700}
    .howto-back:hover{text-decoration:underline}
    /* The prompt in cron/regen_howto_articles.php instructs Claude to use
       ## for top-level sections, which render_launch_markdown() maps to
       <h3> (level = hash-count + 1) -- so h3 is the real top-level style
       here, not h2. Kept h2 styled too in case an article ever uses a
       single #. */
    .howto-article h2{font-size:18px;margin:22px 0 8px}
    .howto-article h2:first-child{margin-top:0}
    .howto-article h3{font-size:15px;margin:18px 0 6px}
    .howto-article h3:first-child{margin-top:0}
    .howto-article p{font-size:14px;line-height:1.65;color:#333;margin:0 0 12px}
    .howto-article ul,.howto-article ol{margin:0 0 12px;padding-left:22px;font-size:14px;line-height:1.6;color:#333}
    .howto-article li{margin-bottom:4px}
    .howto-article code{background:#f3f3f3;padding:1px 5px;border-radius:4px;font-size:12.5px}
    .lc-table-wrap{overflow-x:auto;margin:0 0 14px}
    .lc-table{width:100%;border-collapse:collapse;font-size:13px}
    .lc-table th,.lc-table td{text-align:left;padding:6px 10px;border-bottom:1px solid #eee}
    .lc-table th{font-weight:700;background:#fafafa}
    .lc-code{background:#f5f5f5;border:1px solid #eee;border-radius:6px;padding:10px 12px;font-size:12.5px;overflow-x:auto}
    .howto-open-link{display:inline-block;margin-top:20px;padding:8px 16px;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:6px;text-decoration:none}
    .howto-open-link:hover{background:#5b8e0d;color:#fff}
    .howto-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
    .howto-item{border:1px solid #eee;border-radius:8px;padding:14px 16px;background:white;text-decoration:none;color:#222;font-size:13px;font-weight:700;transition:border-color 100ms,box-shadow 100ms}
    .howto-item:hover{border-color:#c3dfa8;box-shadow:0 2px 6px rgba(0,0,0,.06)}
    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center;border:1px dashed #eee;border-radius:8px;grid-column:1/-1}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('howto', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title"><?= $article ? htmlspecialchars($article['label']) : 'How-To Library' ?></div>
    </header>
    <main class="wrap">
      <?php if ($article): ?>
        <div class="card" style="padding:24px 28px">
          <a class="howto-back" href="howto.php">&larr; All how-tos</a>
          <div class="howto-article"><?= render_launch_markdown($article['body_markdown']) ?></div>
          <a class="howto-open-link" href="<?= htmlspecialchars($article['href']) ?>">Open this feature &rarr;</a>
        </div>
      <?php else: ?>
        <div class="card" style="padding:20px 24px">
          <div class="howto-grid">
            <?php if (!$allArticles): ?>
              <div class="empty-note">No how-to articles yet — they generate automatically overnight.</div>
            <?php else: foreach ($allArticles as $a): ?>
              <a class="howto-item" href="howto.php?key=<?= urlencode($a['page_key']) ?>"><?= htmlspecialchars($a['label']) ?></a>
            <?php endforeach; endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
