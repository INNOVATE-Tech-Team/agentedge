<?php
// Zillow Review Requests — approval queue for review-request emails drafted
// when a dotloop transaction closes. Mirrors backoffice_google_audit.php's
// "Review Requests" tab exactly, minus the Audit/Checklist tabs — there's no
// Zillow equivalent of the Google Places API discovery those drive, since
// Zillow has no public API for this at all. See lib/dotloop.php's
// queue_zillow_review_request_for_loop() (draft creation on SOLD transition).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/notifications.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
$isAdmin  = is_super_admin();
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { header('Location: index.php'); exit; }

// Same MC-scoping convention as backoffice_google_audit.php — leaders see
// status for their own agents but every mutating action stays admin-only.
$myMcSlugs = $isAdmin ? null : my_mc_slugs();
function in_my_mc_scope(?array $myMcSlugs, string $marketCenter): bool {
    return $myMcSlugs === null || in_array(slugify_mc($marketCenter), $myMcSlugs, true);
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$db      = local_db();
$success = '';
$error   = '';

// ── Handle POST (admin-only) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { die('Admins only.'); }
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $row = $db->prepare("SELECT * FROM zillow_review_request_queue WHERE id = ? AND status = 'awaiting_approval'");
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
                    "UPDATE zillow_review_request_queue SET status='sent', subject=?, body=?, actioned_by=?, actioned_at=datetime('now'), sent_at=datetime('now') WHERE id=?"
                )->execute([$subject !== '' ? $subject : $r['subject'], $body !== '' ? $body : $r['body'], $agent['email'], $id]);
                $success = 'Review request sent to ' . implode(', ', $emails) . '.';
            }
        }
    }

    if ($action === 'skip') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare(
            "UPDATE zillow_review_request_queue SET status='skipped', actioned_by=?, actioned_at=datetime('now') WHERE id=? AND status='awaiting_approval'"
        )->execute([$agent['email'], $id]);
        $success = 'Review request skipped.';
    }
}

// ── Queue data — joined against the roster for MC scoping ────────────────────
$rrJoin = "LEFT JOIN innovate_roster ir ON LOWER(TRIM(ir.email)) = LOWER(TRIM(q.agent_email))";
function scoped_zillow_queue(PDO $db, string $rrJoin, string $status, ?array $myMcSlugs, string $order, string $limit = ''): array {
    $rows = $db->query("SELECT q.*, ir.market_center FROM zillow_review_request_queue q {$rrJoin} WHERE q.status {$status} ORDER BY {$order} {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    if ($myMcSlugs !== null) {
        $rows = array_values(array_filter($rows, fn($r) => in_my_mc_scope($myMcSlugs, $r['market_center'] ?: '')));
    }
    return $rows;
}
$pending    = scoped_zillow_queue($db, $rrJoin, "='awaiting_approval'", $myMcSlugs, 'q.created_at DESC');
$blocked    = scoped_zillow_queue($db, $rrJoin, "='blocked_no_link'", $myMcSlugs, 'q.created_at DESC');
$notOptedIn = scoped_zillow_queue($db, $rrJoin, "='blocked_not_opted_in'", $myMcSlugs, 'q.created_at DESC');
$history    = scoped_zillow_queue($db, $rrJoin, "IN ('sent','skipped')", $myMcSlugs, 'q.actioned_at DESC', 'LIMIT 200');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Zillow Review Requests — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fca5a5;border-color:#f87171}

    .ga-table{width:100%;border-collapse:collapse;font-size:13px}
    .ga-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;border-bottom:2px solid #f0f0f0}
    .ga-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}

    .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
    .badge.ok{background:#f0f5e8;color:#5b8e0d}
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
  <?php render_sidebar('bo_zillow_reviews', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Zillow Review Requests</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <?php if ($success): ?><div class="banner ok"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="banner err"><?= h($error) ?></div><?php endif; ?>

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
            <?php if ($isAdmin): ?>
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
            <?php else: ?>
              <div style="font-size:12px;color:#888">Subject: <?= h($r['subject']) ?> — waiting on an admin to approve.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($notOptedIn): ?>
          <h3 style="font-size:13px;font-weight:800;margin:24px 0 10px">Blocked — Agent Hasn't Opted In</h3>
          <?php foreach ($notOptedIn as $r): ?>
            <div class="rr-card">
              <div class="rr-meta">
                <?= h($r['loop_name']) ?> — agent: <?= h($r['agent_email']) ?> — to: <?= h($r['recipient_emails']) ?>
                (<?= h($r['recipient_names']) ?>) — closed <?= h($r['created_at']) ?>
              </div>
              <div style="font-size:12px;color:#b45309">This agent hasn't checked the "Send automatic Zillow review requests" box on their My Profile page yet.</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($blocked): ?>
          <h3 style="font-size:13px;font-weight:800;margin:24px 0 10px">Blocked — Agent Missing Zillow Review Link</h3>
          <?php foreach ($blocked as $r): ?>
            <div class="rr-card">
              <div class="rr-meta">
                <?= h($r['loop_name']) ?> — agent: <?= h($r['agent_email']) ?> — to: <?= h($r['recipient_emails']) ?>
                (<?= h($r['recipient_names']) ?>) — closed <?= h($r['created_at']) ?>
              </div>
              <div style="font-size:12px;color:#b45309">Ask this agent to add their Zillow review link on My Profile — this draft will unblock automatically once one is on file.</div>
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
    </main>
  </div>
</div>
</body>
</html>
