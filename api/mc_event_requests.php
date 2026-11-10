<?php
// Requests for extra Market Center access on mc_events.php.
// POST action=request  {scope:'mcs', mc_slugs:[...]} | {scope:'company'}  → emails Super Admins
// POST action=decide   {id, decision:'approve'|'deny'}                    → Super Admin only
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/mc_event_access.php';

header('Content-Type: application/json');
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$db = local_db();
mc_access_ensure_tables($db);
$me     = strtolower(trim($agent['email'] ?? ''));
$myName = trim(($agent['name'] ?? '') ?: $me);
$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$base   = 'https://agents.innovateonline.com';

if ($action === 'request') {
    if (!can_manage_mc_events() || is_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
    $effective = mc_access_effective_slugs($db, $me, my_mc_slugs());
    $scope = $in['scope'] ?? '';

    $pend = $db->prepare("SELECT 1 FROM mc_event_requests WHERE LOWER(email)=? AND status='pending' AND scope=? AND mc_slugs=?");

    if ($scope === 'company') {
        if (mc_access_has_company_grant($db, $me)) { echo json_encode(['error' => 'You already have company-wide access.']); exit; }
        $pend->execute([$me, 'company', '']);
        if ($pend->fetchColumn()) { echo json_encode(['error' => 'You already have a pending company-wide request.']); exit; }
        $db->prepare("INSERT INTO mc_event_requests (email, name, scope) VALUES (?,?, 'company')")->execute([$me, $myName]);
        $what = "ENTIRE COMPANY (every Market Center)";
    } elseif ($scope === 'mcs') {
        $req = array_values(array_unique(array_filter(array_map('strval', (array)($in['mc_slugs'] ?? [])))));
        if (!$req) { echo json_encode(['error' => 'Choose at least one Market Center.']); exit; }
        // Server-side: only same-state MCs not already available to them.
        $allowed = array_column(mc_access_requestable($db, $effective), null, 'slug');
        $names = [];
        foreach ($req as $s) {
            if (!isset($allowed[$s])) { http_response_code(400); echo json_encode(['error' => 'You can only request Market Centers in your state.']); exit; }
            $names[] = $allowed[$s]['name'] . ' (' . $allowed[$s]['state_code'] . ')';
        }
        sort($req);
        $csv = implode(',', $req);
        $pend->execute([$me, 'mcs', $csv]);
        if ($pend->fetchColumn()) { echo json_encode(['error' => 'You already have a pending request for those Market Centers.']); exit; }
        $db->prepare("INSERT INTO mc_event_requests (email, name, scope, mc_slugs) VALUES (?,?, 'mcs', ?)")->execute([$me, $myName, $csv]);
        $what = implode(', ', $names);
    } else {
        http_response_code(400); echo json_encode(['error' => 'Invalid request']); exit;
    }

    $body = implode("\n", [
        "{$myName} <{$me}> is requesting access to create Market Center events for:",
        "",
        $what,
        "",
        "Approve or deny it here:",
        "{$base}/mc_event_requests.php",
        "",
        "— AgentEdge",
    ]);
    queue_email_to(super_admin_emails(), "Event access request: {$myName}", $body, $me, $myName, $me);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'decide') {
    if (!is_super_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
    $id = (int)($in['id'] ?? 0);
    $dec = $in['decision'] ?? '';
    if (!in_array($dec, ['approve', 'deny'], true)) { http_response_code(400); echo json_encode(['error' => 'Invalid decision']); exit; }
    $st = $db->prepare("SELECT * FROM mc_event_requests WHERE id=? AND status='pending'");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo json_encode(['error' => 'Request not found or already decided.']); exit; }

    $db->beginTransaction();
    if ($dec === 'approve') {
        $slugs = $r['scope'] === 'company' ? ['*'] : array_filter(explode(',', $r['mc_slugs']));
        $ins = $db->prepare("INSERT OR IGNORE INTO mc_event_grants (email, mc_slug, granted_by) VALUES (?,?,?)");
        foreach ($slugs as $s) $ins->execute([$r['email'], $s, $me]);
    }
    $db->prepare("UPDATE mc_event_requests SET status=?, decided_by=?, decided_at=datetime('now') WHERE id=?")
       ->execute([$dec === 'approve' ? 'approved' : 'denied', $me, $id]);
    $db->commit();

    $word = $dec === 'approve' ? 'approved' : 'denied';
    $msg = "Your request for additional Market Center event access was {$word}."
         . ($dec === 'approve' ? "\n\nYou can now create events for them at {$base}/mc_events.php" : "")
         . "\n\n— AgentEdge";
    queue_email_to([$r['email']], "Your event access request was {$word}", $msg, $me, $myName);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
