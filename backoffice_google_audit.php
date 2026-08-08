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
$isAdmin  = is_super_admin();
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { header('Location: index.php'); exit; }

// mc_leader/bic get a view scoped to the Market Center(s) they lead — same
// pattern as backoffice_roster.php. Every mutating action below (refresh,
// approve, skip) stays admin-only; leaders can see status and chase their
// own agents, but can't trigger a real client-facing send themselves.
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

    if ($action === 'request_permission') {
        $emails = array_filter(array_map('trim', $_POST['sel'] ?? []));
        $sent = 0;
        foreach ($emails as $email) {
            $row = $db->prepare(
                "SELECT r.agent_name, TRIM(COALESCE(i.google_place_id,'')) AS place_id, i.review_requests_opt_in,
                        (SELECT status FROM google_place_candidates c WHERE LOWER(TRIM(c.email))=LOWER(TRIM(r.email)) AND c.status='pending') AS candidate_status
                 FROM innovate_roster r
                 LEFT JOIN agent_intake i ON LOWER(TRIM(i.email)) = LOWER(TRIM(r.email))
                 WHERE LOWER(TRIM(r.email)) = LOWER(TRIM(?)) AND r.active = 1 LIMIT 1"
            );
            $row->execute([$email]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r) continue;

            $status = $r['place_id'] === ''
                ? ($r['candidate_status'] === 'pending' ? 'has_candidate' : 'needs_page')
                : (empty($r['review_requests_opt_in']) ? 'not_opted_in' : null);
            if ($status === null) continue; // already fully set up, nothing to ask

            $mail = google_permission_request_email($r['agent_name'], $status);
            queue_email_to([$email], $mail['subject'], $mail['body']);
            $db->prepare(
                "INSERT INTO agent_intake (email, google_permission_requested_at)
                 VALUES (?, datetime('now'))
                 ON CONFLICT(email) DO UPDATE SET google_permission_requested_at = excluded.google_permission_requested_at"
            )->execute([strtolower(trim($email))]);
            $sent++;
        }
        $success = "Sent {$sent} permission-request email(s)." . (count($emails) > $sent ? ' (' . (count($emails) - $sent) . ' skipped — already fully set up or not found.)' : '');
    }
}

// ── Audit tab data ────────────────────────────────────────────────────────────
$auditRows = $db->query(
    "SELECT r.agent_name, r.email, r.market_center, i.google_place_id, i.review_requests_opt_in, i.google_permission_requested_at, g.business_status, g.rating, g.review_count, g.last_checked_at, g.last_error
     FROM innovate_roster r
     LEFT JOIN agent_intake i ON LOWER(TRIM(i.email)) = LOWER(TRIM(r.email))
     LEFT JOIN google_business_audit g ON LOWER(TRIM(g.email)) = LOWER(TRIM(r.email))
     WHERE r.active = 1
     ORDER BY r.market_center, r.agent_name"
)->fetchAll(PDO::FETCH_ASSOC);
if ($myMcSlugs !== null) {
    $auditRows = array_values(array_filter($auditRows, fn($r) => in_my_mc_scope($myMcSlugs, $r['market_center'] ?: '')));
}

// ── Review Requests tab data — joined against the roster for MC scoping ──────
$rrJoin = "LEFT JOIN innovate_roster ir ON LOWER(TRIM(ir.email)) = LOWER(TRIM(q.agent_email))";
function scoped_review_queue(PDO $db, string $rrJoin, string $status, ?array $myMcSlugs, string $order, string $limit = ''): array {
    $rows = $db->query("SELECT q.*, ir.market_center FROM review_request_queue q {$rrJoin} WHERE q.status {$status} ORDER BY {$order} {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    if ($myMcSlugs !== null) {
        $rows = array_values(array_filter($rows, fn($r) => in_my_mc_scope($myMcSlugs, $r['market_center'] ?: '')));
    }
    return $rows;
}
$pending    = scoped_review_queue($db, $rrJoin, "='awaiting_approval'", $myMcSlugs, 'q.created_at DESC');
$blocked    = scoped_review_queue($db, $rrJoin, "='blocked_no_place_id'", $myMcSlugs, 'q.created_at DESC');
$notOptedIn = scoped_review_queue($db, $rrJoin, "='blocked_not_opted_in'", $myMcSlugs, 'q.created_at DESC');
$history    = scoped_review_queue($db, $rrJoin, "IN ('sent','skipped')", $myMcSlugs, 'q.actioned_at DESC', 'LIMIT 200');

// ── Google Place candidates awaiting the agent's own confirmation ────────────
$pendingCandidates = $db->query(
    "SELECT c.*, r.agent_name, r.market_center FROM google_place_candidates c
     LEFT JOIN innovate_roster r ON LOWER(TRIM(r.email)) = LOWER(TRIM(c.email))
     WHERE c.status = 'pending' ORDER BY c.match_score DESC"
)->fetchAll(PDO::FETCH_ASSOC);
if ($myMcSlugs !== null) {
    $pendingCandidates = array_values(array_filter($pendingCandidates, fn($r) => in_my_mc_scope($myMcSlugs, $r['market_center'] ?: '')));
}

// ── Checklist tab data: agents with no Place ID AND no candidate at all ──────
$needsPageRows = $db->query(
    "SELECT r.agent_name, r.email, r.market_center
     FROM innovate_roster r
     LEFT JOIN agent_intake i ON LOWER(TRIM(i.email)) = LOWER(TRIM(r.email))
     LEFT JOIN google_place_candidates c ON LOWER(TRIM(c.email)) = LOWER(TRIM(r.email))
     WHERE r.active = 1
       AND TRIM(COALESCE(i.google_place_id, '')) = ''
       AND (c.email IS NULL OR c.status = 'dismissed')
     ORDER BY r.market_center, r.agent_name"
)->fetchAll(PDO::FETCH_ASSOC);
if ($myMcSlugs !== null) {
    $needsPageRows = array_values(array_filter($needsPageRows, fn($r) => in_my_mc_scope($myMcSlugs, $r['market_center'] ?: '')));
}
$needsPageByMc = [];
foreach ($needsPageRows as $r) {
    $mc = $r['market_center'] ?: '(No Market Center)';
    $needsPageByMc[$mc][] = $r;
}
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
          <button class="ga-tab" id="tab-btn-checklist" onclick="switchTab('checklist')">
            Checklist<?= $needsPageRows ? ' (' . count($needsPageRows) . ')' : '' ?>
          </button>
          <button class="ga-tab" id="tab-btn-requests" onclick="switchTab('requests')">
            Review Requests<?= $pending ? ' (' . count($pending) . ')' : '' ?>
          </button>
        </div>

        <!-- ── Audit tab ── -->
        <div class="tab-panel active" id="tab-audit">
          <?php if ($isAdmin): ?>
          <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="refresh_all">
              <button type="submit" class="btn-primary">Refresh Now</button>
            </form>
          </div>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
          <form method="post" id="audit-bulk-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="request_permission">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
              <button type="submit" class="btn-sm">Request Permission for Selected</button>
              <span style="font-size:11px;color:#888">Emails whoever's checked, tailored to what's missing for them. Skips anyone already fully set up.</span>
            </div>
          <?php endif; ?>
          <table class="ga-table">
            <thead><tr>
              <?php if ($isAdmin): ?><th><input type="checkbox" onclick="document.querySelectorAll('.audit-sel').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
              <th>Agent</th><th>Place ID</th><th>Review Requests</th><th>Status</th><th>Rating</th><th>Reviews</th><th>Last Checked</th><th>Last Asked</th>
            </tr></thead>
            <tbody>
            <?php foreach ($auditRows as $row): $placeId = trim($row['google_place_id'] ?? ''); ?>
              <tr>
                <?php if ($isAdmin): ?><td><input type="checkbox" class="audit-sel" name="sel[]" value="<?= h($row['email']) ?>" form="audit-bulk-form"></td><?php endif; ?>
                <td><?= h($row['agent_name']) ?><br><span style="color:#aaa;font-size:11px"><?= h($row['email']) ?></span></td>
                <td>
                  <?php if ($placeId === ''): ?>
                    <span class="badge bad">Missing</span>
                  <?php else: ?>
                    <span class="badge ok">On file</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($row['review_requests_opt_in'])): ?>
                    <span class="badge ok">Opted in</span>
                  <?php else: ?>
                    <span class="badge muted">Not opted in</span>
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
                <td><?= h($row['google_permission_requested_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$auditRows): ?>
              <tr><td colspan="<?= $isAdmin ? 9 : 8 ?>" class="empty-note">No active agents found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>

          <?php if ($pendingCandidates): ?>
            <h3 style="font-size:13px;font-weight:800;margin:24px 0 10px">Discovered Listings Awaiting Agent Confirmation (<?= count($pendingCandidates) ?>)</h3>
            <p style="font-size:12px;color:#888;margin:0 0 14px">These are automated guesses (Places API text search) — nothing is used until the agent confirms it's really them on their own My Profile page.</p>
            <table class="ga-table">
              <thead><tr><th>Agent</th><th>Candidate Business</th><th>Rating</th><th>Reviews</th><th>Address</th><th>Match Score</th></tr></thead>
              <tbody>
              <?php foreach ($pendingCandidates as $c): ?>
                <tr>
                  <td><?= h($c['agent_name'] ?: $c['email']) ?></td>
                  <td><?= h($c['candidate_name']) ?></td>
                  <td><?= $c['rating'] !== null ? h($c['rating']) : '—' ?></td>
                  <td><?= (int)$c['review_count'] ?></td>
                  <td><?= h($c['formatted_addr']) ?></td>
                  <td><?= (int)$c['match_score'] ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- ── Checklist tab: agents with no listing at all, grouped by Market Center ── -->
        <div class="tab-panel" id="tab-checklist">
          <p style="font-size:12px;color:#888;margin:0 0 16px">
            No Google Business Page found for these agents at all (no Place ID on file, and the automated search didn't turn up a plausible match either). MC leaders: this is your list to chase — have them create a page at
            <a href="https://business.google.com/create" target="_blank" rel="noopener">business.google.com/create</a>, then their listing will get picked up automatically or they can self-enter the Place ID on My Profile.
          </p>
          <?php if (!$needsPageByMc): ?>
            <div class="empty-note">Nobody in scope is missing a page — nice.</div>
          <?php endif; ?>
          <?php if ($isAdmin && $needsPageByMc): ?>
          <form method="post" id="checklist-bulk-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="request_permission">
            <button type="submit" class="btn-sm" style="margin-bottom:10px">Request Permission for Selected</button>
          <?php endif; ?>
          <?php foreach ($needsPageByMc as $mc => $list): ?>
            <h3 style="font-size:13px;font-weight:800;margin:20px 0 8px"><?= h($mc) ?> (<?= count($list) ?>)</h3>
            <table class="ga-table">
              <thead><tr><?php if ($isAdmin): ?><th><input type="checkbox" onclick="this.closest('table').querySelectorAll('.cl-sel').forEach(c=>c.checked=this.checked)"></th><?php endif; ?><th>Agent</th><th>Email</th></tr></thead>
              <tbody>
              <?php foreach ($list as $r): ?>
                <tr>
                  <?php if ($isAdmin): ?><td><input type="checkbox" class="cl-sel" name="sel[]" value="<?= h($r['email']) ?>" form="checklist-bulk-form"></td><?php endif; ?>
                  <td><?= h($r['agent_name']) ?></td><td><?= h($r['email']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endforeach; ?>
          <?php if ($isAdmin && $needsPageByMc): ?></form><?php endif; ?>
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
                <div style="font-size:12px;color:#b45309">This agent hasn't checked the "Send automatic Google review requests" box on their My Profile page yet.</div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

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
