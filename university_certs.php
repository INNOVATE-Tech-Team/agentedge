<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

// Print mode: ?print=1&code=INU-XXXX
$printCode = trim($_GET['code'] ?? '');
$printMode = !empty($_GET['print']) && $printCode;

if ($printMode) {
    $cs = $db->prepare(
        "SELECT uc.*, c.title as course_title, COALESCE(cat.name,'') as cat_name
         FROM uni_certs uc
         JOIN uni_courses c ON c.id=uc.course_id
         LEFT JOIN uni_categories cat ON cat.id=c.category_id
         WHERE uc.cert_code=? AND uc.agent_email=?"
    );
    $cs->execute([$printCode, $email]);
    $certRow = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$certRow) { header('Location: university_certs.php'); exit; }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Certificate — <?= htmlspecialchars($certRow['course_title']) ?></title>
      <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;600;700&display=swap');
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#f5f5f0;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
        .cert{width:800px;background:white;border:3px solid #1a1a1a;padding:60px;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.15)}
        .cert::before{content:'';position:absolute;inset:8px;border:1px solid #82C112;pointer-events:none}
        .cert-logo{display:flex;align-items:center;gap:12px;margin-bottom:32px;justify-content:center}
        .cert-logo-squares{display:flex;gap:4px}
        .cert-logo-sq{width:14px;height:14px;border-radius:2px}
        .cert-logo-sq:nth-child(1){background:#82C112}
        .cert-logo-sq:nth-child(2){background:#5b8e0d}
        .cert-logo-sq:nth-child(3){background:#3d6009}
        .cert-logo-text{font-weight:800;font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#1a1a1a}
        .cert-divider{height:2px;background:linear-gradient(90deg,transparent,#82C112,transparent);margin:0 0 32px}
        .cert-presents{text-align:center;font-size:13px;text-transform:uppercase;letter-spacing:.2em;color:#888;margin-bottom:12px}
        .cert-of-completion{text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.4em;color:#aaa;margin-bottom:28px}
        .cert-name{text-align:center;font-size:40px;font-weight:900;color:#1a1a1a;border-bottom:2px solid #1a1a1a;padding-bottom:8px;margin:0 40px 28px;letter-spacing:.02em}
        .cert-course-label{text-align:center;font-size:12px;text-transform:uppercase;letter-spacing:.2em;color:#888;margin-bottom:10px}
        .cert-course-title{text-align:center;font-size:22px;font-weight:800;color:#1a1a1a;margin-bottom:8px}
        .cert-category{text-align:center;font-size:13px;color:#82C112;font-weight:700;margin-bottom:32px}
        .cert-footer{display:flex;justify-content:space-between;align-items:flex-end;margin-top:40px;border-top:1px solid #eee;padding-top:20px}
        .cert-date-block,.cert-code-block{text-align:center}
        .cert-footer-label{font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:#bbb;margin-bottom:4px}
        .cert-footer-value{font-size:13px;font-weight:700;color:#555}
        .cert-seal{width:80px;height:80px;background:linear-gradient(135deg,#82C112,#5b8e0d);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 2px 8px rgba(130,193,18,.4)}
        .no-print{text-align:center;margin-top:20px}
        .print-btn{padding:10px 28px;background:#1a1a1a;color:white;font-weight:700;font-size:14px;border:none;border-radius:6px;cursor:pointer}
        .print-btn:hover{background:#333}
        @media print{body{background:white;padding:0}.cert{box-shadow:none;border-color:#1a1a1a}.no-print{display:none}}
      </style>
    </head>
    <body>
      <div>
        <div class="cert">
          <div class="cert-logo">
            <div class="cert-logo-squares">
              <div class="cert-logo-sq"></div>
              <div class="cert-logo-sq"></div>
              <div class="cert-logo-sq"></div>
            </div>
            <div class="cert-logo-text">INNOVATE University</div>
          </div>
          <div class="cert-divider"></div>
          <div class="cert-presents">This certificate is proudly presented to</div>
          <div class="cert-name"><?= htmlspecialchars($agent['name'] ?: $agent['email']) ?></div>
          <div class="cert-of-completion">In recognition of the successful completion of</div>
          <div class="cert-course-title"><?= htmlspecialchars($certRow['course_title']) ?></div>
          <?php if ($certRow['cat_name']): ?>
          <div class="cert-category"><?= htmlspecialchars($certRow['cat_name']) ?></div>
          <?php endif; ?>
          <div class="cert-footer">
            <div class="cert-date-block">
              <div class="cert-footer-label">Date Issued</div>
              <div class="cert-footer-value"><?= date('F j, Y', strtotime($certRow['issued_at'])) ?></div>
            </div>
            <div class="cert-seal">🎓</div>
            <div class="cert-code-block">
              <div class="cert-footer-label">Certificate ID</div>
              <div class="cert-footer-value" style="font-family:monospace"><?= htmlspecialchars($certRow['cert_code']) ?></div>
            </div>
          </div>
        </div>
        <div class="no-print">
          <button class="print-btn" onclick="window.print()">🖨 Print Certificate</button>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Normal certs list view
$certsQ = $db->prepare(
    "SELECT uc.*, c.title as course_title, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon
     FROM uni_certs uc
     JOIN uni_courses c ON c.id=uc.course_id
     LEFT JOIN uni_categories cat ON cat.id=c.category_id
     WHERE uc.agent_email=?
     ORDER BY uc.issued_at DESC"
);
$certsQ->execute([$email]);
$certs = $certsQ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Certificates — INNOVATE University</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .cert-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
    .cert-card{background:white;border:2px solid #e0d080;border-radius:12px;padding:24px;background:linear-gradient(135deg,#fffef5,#fffbea);transition:box-shadow 150ms}
    .cert-card:hover{box-shadow:0 4px 16px rgba(245,200,66,.2)}
    .cert-card-top{display:flex;align-items:center;gap:14px;margin-bottom:16px}
    .cert-seal{width:52px;height:52px;background:linear-gradient(135deg,#82C112,#5b8e0d);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
    .cert-card-cat{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:3px}
    .cert-card-title{font-size:14px;font-weight:800;color:#111;line-height:1.3}
    .cert-card-meta{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f0e880;padding-top:14px;flex-wrap:wrap;gap:8px}
    .cert-date{font-size:11px;color:#888}
    .cert-code{font-size:10px;font-family:monospace;background:rgba(0,0,0,.05);padding:2px 8px;border-radius:4px;color:#666}
    .cert-print-btn{padding:6px 14px;background:#f5c842;color:#000;font-weight:800;font-size:11px;border-radius:6px;text-decoration:none;white-space:nowrap}
    .cert-print-btn:hover{background:#d4a800}
    .empty-state{text-align:center;padding:60px 20px}
    .empty-state .es-icon{font-size:48px;margin-bottom:12px}
    .empty-state p{font-size:14px;color:#bbb}
    .empty-state a{color:#5b8e0d;font-weight:700}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">INNOVATE University</div>
    </header>
    <main class="wrap">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-size:20px;font-weight:900;color:#111">🏆 My Certificates</div>
          <div style="font-size:13px;color:#888;margin-top:3px"><?= count($certs) ?> certificate<?= count($certs) !== 1 ? 's' : '' ?> earned</div>
        </div>
        <a href="university.php" style="font-size:13px;color:#5b8e0d;font-weight:700;text-decoration:none">← Back to Catalog</a>
      </div>

      <?php if (!$certs): ?>
      <div class="empty-state">
        <div class="es-icon">🎓</div>
        <p>No certificates yet — complete a course to earn one!<br><a href="university.php">Browse courses →</a></p>
      </div>
      <?php else: ?>
      <div class="cert-grid">
        <?php foreach ($certs as $cert): ?>
        <div class="cert-card">
          <div class="cert-card-top">
            <div class="cert-seal">🏆</div>
            <div>
              <div class="cert-card-cat"><?= htmlspecialchars($cert['cat_icon'] . ' ' . $cert['cat_name']) ?></div>
              <div class="cert-card-title"><?= htmlspecialchars($cert['course_title']) ?></div>
            </div>
          </div>
          <div class="cert-card-meta">
            <div>
              <div class="cert-date">Issued <?= date('M j, Y', strtotime($cert['issued_at'])) ?></div>
              <div class="cert-code"><?= htmlspecialchars($cert['cert_code']) ?></div>
            </div>
            <a class="cert-print-btn" href="university_certs.php?print=1&code=<?= urlencode($cert['cert_code']) ?>" target="_blank">Print</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
