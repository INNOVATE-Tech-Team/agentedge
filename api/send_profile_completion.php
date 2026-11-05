<?php
// Admin action: email an agent (or every agent with an incomplete profile) a
// link to fill in just their missing required fields.
// POST {action:'single', email, override_cooldown?}  → one agent
// POST {action:'bulk_preview'}                       → who WOULD be emailed (sends nothing)
// POST {action:'bulk_incomplete', confirm_hash}      → send to exactly the previewed list
//
// Link rules (lib/agent_profile.php): each link expires after 30 days; issuing
// a new link revokes the agent's earlier links; an agent can't be sent another
// link within 7 days unless a Super Admin explicitly overrides the cooldown;
// agents actively onboarding never get one (they use their intake link).
// Links issued before these rules keep working as they did (no auto-expiry).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$admin = current_agent();
if (!$admin || !is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';


function send_completion_email(string $email, string $name, string $token, array $cfg, array $missing = []): void {
    $link    = 'https://agents.innovateonline.com/complete_profile.php?token=' . urlencode($token);
    $first   = $name ? explode(' ', trim($name))[0] : 'there';
    $subject = 'A few things are missing from your AgentEdge profile';

    $fieldItems = implode('', array_map(
        fn($f) => '<li style="margin-bottom:5px">' . htmlspecialchars($f['label'], ENT_QUOTES) . '</li>',
        $missing
    ));
    $fieldListHtml = $fieldItems !== ''
        ? '<ul style="margin:0 0 22px;padding-left:20px;color:#333;font-size:14px;line-height:1.6">' . $fieldItems . '</ul>'
        : '';

    $htmlBody = <<<HTML
<div style="background:#f4f5f6;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif">
  <div style="max-width:480px;margin:0 auto">
    <div style="background:#111111;border-radius:10px 10px 0 0;padding:18px 24px">
      <div style="color:#ffffff;font-size:16px;font-weight:700;letter-spacing:.02em">INNOVATE <span style="color:#82C112">AgentEdge</span></div>
    </div>
    <div style="background:#ffffff;border-radius:0 0 10px 10px;padding:28px 24px;box-shadow:0 2px 10px rgba(0,0,0,.06)">
      <p style="margin:0 0 16px;font-size:15px;color:#111111">Hi {$first},</p>
      <p style="margin:0 0 18px;font-size:14px;color:#333333;line-height:1.6">A few required fields are still missing from your agent profile. This link only asks for what's actually missing — everything else on file stays as-is.</p>
      {$fieldListHtml}
      <div style="text-align:center;margin:26px 0">
        <a href="{$link}" style="display:inline-block;background:#82C112;color:#111111;font-weight:700;font-size:14px;text-decoration:none;padding:12px 28px;border-radius:8px">Complete My Profile</a>
      </div>
      <p style="margin:0 0 10px;font-size:12px;color:#999999;line-height:1.5">This link expires in 30 days.</p>
      <p style="margin:0;font-size:12px;color:#999999;line-height:1.5">If the button doesn't work, copy this link into your browser:<br><a href="{$link}" style="color:#5b8e0d">{$link}</a></p>
    </div>
    <p style="text-align:center;color:#aaaaaa;font-size:11px;margin-top:16px">— INNOVATE Real Estate</p>
  </div>
</div>
HTML;

    send_email_sendgrid($email, $subject, $htmlBody, $cfg, true);
}

$db  = local_db();
$cfg = cfg();
if (!profile_link_schema_ready($db)) {
    // The app never alters tables itself; until the reviewed migration has
    // been applied, no new links can be issued (existing links still work).
    echo json_encode(['ok' => false, 'error' => PROFILE_STATUS_MIGRATION_PENDING, 'migration_pending' => true]);
    exit;
}
$me  = $admin['email'] ?? '';

// Everyone the bulk send would reach right now, plus who it skips and why.
function bulk_candidates(PDO $db): array {
    $rows = $db->query(
        "SELECT i.email, i.full_name
         FROM agent_intake i
         LEFT JOIN agent_admin aa ON LOWER(aa.email) = i.email
         WHERE COALESCE(aa.terminated_date, '') = ''
         ORDER BY i.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = ['recipients' => [], 'skipped_cooldown' => [], 'skipped_onboarding' => 0];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (is_actively_onboarding($email)) { $out['skipped_onboarding']++; continue; }
        $missing = get_missing_required_fields($email);
        if (!$missing) continue;
        if (profile_link_in_cooldown($db, $email)) {
            $out['skipped_cooldown'][] = ['email' => $email, 'name' => $r['full_name'] ?? '', 'last_sent' => profile_link_last_sent($db, $email)];
            continue;
        }
        $out['recipients'][] = ['email' => $email, 'name' => $r['full_name'] ?? '', 'missing' => array_column($missing, 'label')];
    }
    $out['confirm_hash'] = hash('sha256', implode("\n", array_column($out['recipients'], 'email')));
    return $out;
}

if ($action === 'single') {
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid email']);
        exit;
    }
    if (is_actively_onboarding($email)) {
        echo json_encode(['ok' => false, 'error' => 'This agent is in the Onboarding Queue. Use "Resend Intake" there instead.']);
        exit;
    }
    $missing = get_missing_required_fields($email);
    if (!$missing) {
        echo json_encode(['ok' => false, 'error' => 'This agent\'s profile is already complete.']);
        exit;
    }
    $override = !empty($body['override_cooldown']);
    if (profile_link_in_cooldown($db, $email)) {
        if (!$override) {
            echo json_encode(['ok' => false, 'error' => 'A link was already sent to this agent in the last ' . PROFILE_LINK_COOLDOWN_DAYS . ' days.',
                              'cooldown' => true, 'last_sent' => profile_link_last_sent($db, $email), 'can_override' => is_super_admin()]);
            exit;
        }
        if (!is_super_admin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only a Super Admin can override the ' . PROFILE_LINK_COOLDOWN_DAYS . '-day resend cooldown.']);
            exit;
        }
    } else {
        $override = false;
    }
    $st = $db->prepare("SELECT full_name FROM agent_intake WHERE email = ?");
    $st->execute([$email]);
    $name = $st->fetchColumn() ?: '';

    $token = profile_link_issue($db, $email, $me, $override);
    send_completion_email($email, $name, $token, $cfg, $missing);

    echo json_encode(['ok' => true, 'sent' => 1, 'cooldown_overridden' => $override]);
    exit;
}

if ($action === 'bulk_preview') {
    echo json_encode(['ok' => true] + bulk_candidates($db));
    exit;
}

if ($action === 'bulk_incomplete') {
    // Only sends to the exact list the admin just previewed and confirmed —
    // if anything changed since (someone completed their profile, a link went
    // out, someone entered onboarding) the admin has to preview again.
    $c = bulk_candidates($db);
    $confirm = (string)($body['confirm_hash'] ?? '');
    if ($confirm === '' || !hash_equals($c['confirm_hash'], $confirm)) {
        echo json_encode(['ok' => false, 'error' => 'The recipient list changed since your preview. Please preview again before sending.', 'stale_preview' => true]);
        exit;
    }
    $sent = 0;
    foreach ($c['recipients'] as $r) {
        $token = profile_link_issue($db, $r['email'], $me);
        send_completion_email($r['email'], $r['name'], $token, $cfg, get_missing_required_fields($r['email']));
        $sent++;
    }
    echo json_encode(['ok' => true, 'sent' => $sent]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
