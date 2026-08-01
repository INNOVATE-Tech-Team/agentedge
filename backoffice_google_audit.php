<?php
// Google Business Audit — monitors agents' Google Business Profile listings
// (rating, review count, open/closed status) and hosts the approval queue for
// review-request emails drafted when a dotloop transaction closes.
// See lib/google_business.php (Places API sync) and lib/dotloop.php's
// queue_review_request_for_loop() (draft creation on SOLD transition).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/google_business.php';
require_once __DIR__ . '/lib/notifications.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$db      = local_db();
$success = '';
$error   = '';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');
    $action = $_POST['action'] ?? '';

    if ($action === 'refresh_all') {
        $result = google_business_sync_all();
        $success = "Checked {$result['checked']} agent(s)." . (!empty($result['errors']) ? ' ' . count($result['errors']) . ' error(s) — see rows below.' : '');
    }

    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $row = $db->prepare("SELECT * FROM review_request_queue WHERE id = ? AND status = 'awaiting_approval'");
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $error = 'That review request is no longer awaiting approval.';
        } else {
            $emails = array_filter(array_map('trim', explode(',', $r['recipient_emails'])));
            if (!$emails) {
                $error = 'No recipient email on file for that request.';
            } else {
                queue_email_to($emails, $subject !== '' ? $subject : $r['subject'], $body !== '' ? $body : $r['body']);
                $db->prepare(
                    "UPDATE review_request_queue SET status='sent', subject=?, body=?, actioned_by=?, actioned_at=datetime('now'), sent_at=datetime('now') WHERE id=?"
                )->execute([$subject !== '' ? $subject : $r['subject'], $body !== '' ? $body : $r['body'], $agent['email'], $id]);
                $success = 'Review request sent to ' . implode(', ', $emails) . '.';
            }
        }
    }

    if ($action === 'skip') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare(
            "UPDATE review_request_queue SET status='skipped', actioned_by=?, actioned_at=datetime('now') WHERE id=? AND status='awaiting_approval'"
        )->execute([$agent['email'], $id]);
        $success = 'Review request skipped.';
    }
}

// ── Audit tab data ────────────────────────────────────────────────────────────
$auditRows = $db->query(
    "SELECT r.agent_name, r.email, i.google_place_id, g.business_status, g.rating, g.review_count, g.last_checked_at, g.last_error
     FROM innovate_roster r
     LEFT JOIN agent_intake i ON LOWER(TRIM(i.email)) = LOWER(TRIM(r.email))
     LEFT JOIN google_business_audit g ON LOWER(TRIM(g.email)) = LOWER(TRIM(r.email))
     WHERE r.active = 1
     ORDER BY r.agent_name"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Review Requests tab data ──────────────────────────────────────────────────
$pending = $db->query("SELECT * FROM review_request_queue WHERE status='awaiting_approval' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$blocked = $db->query("SELECT * FROM review_request_queue WHERE status='blocked_no_place_id' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$history = $db->query("SELECT * FROM review_request_queue WHERE status IN ('sent','skipped') ORDER BY actioned_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Google Business Audit — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fca5a5;border-color:#f87171}

    .ga-tabs{display:flex;gap:4px;border-bottom:2px solid #f0f0f0;margin-bottom:20px}
    .ga-tab{padding:10px 4px;margin-bottom:-2px;background:none;border:none;border-bottom:2px solid transparent;font-size:13px;font-weight:700;color:#888;cursor:pointer}
    .ga-tab+.ga-tab{margin-left:16px}
    .ga-tab:hover{color:#333}
    .ga-tab.active{color:#5b8e0d;border-bottom-color:#82C112}
    .tab-panel{display:none}
    .tab-panel.active{display:block}

    .ga-table{width:100%;border-collapse:collapse;font-size:13px}
    .ga-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;border-bottom:2px solid #f0f0f0}
    .ga-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}

    .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
    .badge.ok{background:#f0f5e8;color:#5b8e0d}
    .badge.warn{background:#fffbeb;color:#b45309}
    .badge.bad{background:#fee2e2;color:#c00}
    .badge.muted{background:#f3f3f3;color:#888}

    .banner{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
    .banner.ok{background:#f0f5e8;color:#5b8e0d;border:1px solid #c3dfa8}
    .banner.err{background:#fee2e2;color:#c00;border:1px solid #f5c6c6}

    .rr-card{border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:14px;background:white}
    .rr-meta{font-size:12px;color:#888;margin-bottom:10px}
    .rr-field{margin-bottom:10px}
    .rr-field label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .rr-field input,.rr-field textarea{width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box}
    .rr-field textarea{min-height:100px;resize:vertical}
    .rr-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_google_audit', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Google Business Audit</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <?php if ($success): ?><div class="banner ok"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="banner err"><?= h($error) ?></div><?php endif; ?>

        <div class="ga-tabs">
          <button class="ga-tab active" id="tab-btn-audit" onclick="switchTab('audit')">Audit</button>
          <button class="ga-tab" id="tab-btn-requests" onclick="switchTab('requests')">
            Review Requests<?= $pending ? ' (' . count($pending) . ')' : '' ?>
          </button>
        </div>

        <!-- ── Audit tab ── -->
        <div class="tab-panel active" id="tab-audit">
          <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="refresh_all">
              <button type="submit" class="btn-primary">Refresh Now</button>
            </form>
          </div>
          <table class="ga-table">
            <thead><tr>
              <th>Agent</th><th>Place ID</th><th>Status</th><th>Rating</th><th>Reviews</th><th>Last Checked</th>
            </tr></thead>
            <tbody>
            <?php foreach ($auditRows as $row): $placeId = trim($row['google_place_id'] ?? ''); ?>
              <tr>
                <td><?= h($row['agent_name']) ?><br><span style="color:#aaa;font-size:11px"><?= h($row['email']) ?></span></td>
                <td>
                  <?php if ($placeId === ''): ?>
                    <span class="badge bad">Missing</span>
                  <?php else: ?>
                    <span class="badge ok">On file</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($row['last_error']): ?>
                    <span class="badge bad" title="<?= h($row['last_error']) ?>">Error</span>
                  <?php elseif ($row['business_status'] === 'OPERATIONAL'): ?>
                    <span class="badge ok">Operational</span>
                  <?php elseif (in_array($row['business_status'], ['CLOSED_TEMPORARILY','CLOSED_PERMANENTLY'], true)): ?>
                    <span class="badge warn"><?= h($row['business_status']) ?></span>
                  <?php else: ?>
                    <span class="badge muted">Not checked</span>
                  <?php endif; ?>
                </td>
                <td><?= $row['rating'] !== null ? h($row['rating']) : '—' ?></td>
                <td><?= (int)($row['review_count'] ?? 0) ?></td>
                <td><?= h($row['last_checked_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$auditRows): ?>
              <tr><td colspan="6" class="empty-note">No active agents found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ── Review Requests tab ── -->
        <div class="tab-panel" id="tab-requests">
          <h3 style="font-size:13px;font-weight:800;margin:0 0 10px">Awaiting Approval</h3>
          <?php if (!$pending): ?>
            <div class="empty-note">Nothing waiting on approval right now.</div>
          <?php endif; ?>
          <?php foreach ($pending as $r): ?>
            <div class="rr-card">
              <div class="rr-meta">
                <?= h($r['loop_name']) ?> — agent: <?= h($r['agent_email']) ?> — to: <?= h($r['recipient_emails']) ?>
                (<?= h($r['recipient_names']) ?>) — closed <?= h($r['created_at']) ?>
              </div>
              <form method="post" id="rr-form-<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <div class="rr-field"><label>Subject</label><input type="text" name="subject" value="<?= h($r['subject']) ?>"></div>
                <div class="rr-field"><label>Body (HTML)</label><textarea name="body"><?= h($r['body']) ?></textarea></div>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="skip">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <div class="rr-actions">
                  <button type="submit" class="btn-sm btn-danger">Skip</button>
                  <button type="submit" form="rr-form-<?= (int)$r['id'] ?>" class="btn-primary">Approve &amp; Send</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>

          <?php if ($blocked): ?>
            <h3 style="font-size:13px;font-weight:800;margin:24px 0 10px">Blocked — Agent Missing Google Place ID</h3>
            <?php foreach ($blocked as $r): ?>
              <div class="rr-card">
                <div class="rr-meta">
                  <?= h($r['loop_name']) ?> — agent: <?= h($r['agent_email']) ?> — to: <?= h($r['recipient_emails']) ?>
                  (<?= h($r['recipient_names']) ?>) — closed <?= h($r['created_at']) ?>
                </div>
                <div style="font-size:12px;color:#b45309">Ask this agent to add their Google Place ID on My Profile — this draft will unblock automatically once one is on file.</div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <h3 style="font-size:13px;font-weight:800;margin:24px 0 10px">History</h3>
          <table class="ga-table">
            <thead><tr><th>Loop</th><th>Agent</th><th>Recipients</th><th>Status</th><th>Actioned</th></tr></thead>
            <tbody>
            <?php foreach ($history as $r): ?>
              <tr>
                <td><?= h($r['loop_name']) ?></td>
                <td><?= h($r['agent_email']) ?></td>
                <td><?= h($r['recipient_emails']) ?></td>
                <td><span class="badge <?= $r['status'] === 'sent' ? 'ok' : 'muted' ?>"><?= h($r['status']) ?></span></td>
                <td><?= h($r['actioned_at'] ?? '—') ?> <?= $r['actioned_by'] ? 'by ' . h($r['actioned_by']) : '' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
              <tr><td colspan="5" class="empty-note">No approved or skipped requests yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>
</div>
<script>
function switchTab(name) {
  document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.ga-tab').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.getElementById('tab-btn-' + name).classList.add('active');
}
</script>
</body>
</html>
