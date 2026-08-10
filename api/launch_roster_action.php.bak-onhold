<?php
// Launch Schedule / Launch Coaching roster — launch_schedule.php, launch_coaching.php.
// GET  ?darwin_search=...          → can_manage_launch_roster(): Darwin agent name search
// POST {action:'create'}          → add a roster row
// POST {action:'update_fields'}   → partial update of state/office/coach/notes/start_date
// POST {action:'set_status'}      → active | graduated | dropped
// POST {action:'link_darwin'}     → attach a darwin_agent_person_id (deals now read from Darwin)
// POST {action:'unlink_darwin'}   → detach it
// POST {action:'set_deals_override'} → manual deal count (or null to clear)
// POST {action:'log_deal'}        → record one closed transaction (deal_date, notes)
// GET  ?deals_for=<roster_id>     → can_manage_launch_roster(): that row's logged deals
// POST {action:'delete_deal'}     → remove one logged deal
// POST {action:'delete'}          → remove a roster row
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/launch_roster.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
if (!can_manage_launch_roster()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not authorized']); exit; }

$pdo = local_db();

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['darwin_search'])) {
        jok(['results' => launch_roster_darwin_search($pdo, (string)$_GET['darwin_search'])]);
    }
    if (isset($_GET['deals_for'])) {
        jok(['deals' => launch_roster_deals_for($pdo, (int)$_GET['deals_for'])]);
    }
    jerr('unknown GET request');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'create') {
    $name = trim($body['agent_name'] ?? '');
    if ($name === '') jerr('agent_name required');
    $pdo->prepare(
        "INSERT INTO launch_roster (agent_name, state, office, coach, start_date, notes, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 'active', datetime('now'), datetime('now'))"
    )->execute([
        $name,
        trim($body['state'] ?? ''),
        trim($body['office'] ?? ''),
        trim($body['coach'] ?? ''),
        trim($body['start_date'] ?? ''),
        trim($body['notes'] ?? ''),
    ]);
    jok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update_fields') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');

    $fields = [];
    $params = [];
    foreach (['agent_name', 'state', 'office', 'coach', 'start_date', 'notes'] as $col) {
        if (array_key_exists($col, $body)) { $fields[] = "$col=?"; $params[] = trim((string)$body[$col]); }
    }
    if (!$fields) jerr('no fields to update');
    $params[] = $id;
    $pdo->prepare("UPDATE launch_roster SET " . implode(', ', $fields) . ", updated_at=datetime('now') WHERE id=?")->execute($params);
    jok();
}

if ($action === 'set_status') {
    $id     = (int)($body['id'] ?? 0);
    $status = preg_replace('/[^a-z_]/', '', $body['status'] ?? '');
    if (!$id || !in_array($status, ['active', 'graduated', 'dropped'], true)) jerr('id and a valid status required');

    $graduatedAt = $status === 'graduated' ? ", graduated_at=date('now')" : ", graduated_at=''";
    $pdo->prepare("UPDATE launch_roster SET status=?{$graduatedAt}, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
    jok();
}

if ($action === 'link_darwin') {
    $id    = (int)($body['id'] ?? 0);
    $dpid  = (int)($body['darwin_agent_person_id'] ?? 0);
    if (!$id || !$dpid) jerr('id and darwin_agent_person_id required');
    $pdo->prepare("UPDATE launch_roster SET darwin_agent_person_id=?, updated_at=datetime('now') WHERE id=?")->execute([$dpid, $id]);
    launch_roster_recalc_graduation($pdo);
    jok();
}

if ($action === 'unlink_darwin') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');
    $pdo->prepare("UPDATE launch_roster SET darwin_agent_person_id=NULL, updated_at=datetime('now') WHERE id=?")->execute([$id]);
    jok();
}

if ($action === 'set_deals_override') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');
    $raw = $body['deals_override'] ?? null;
    $val = ($raw === null || $raw === '') ? null : max(0, (int)$raw);
    $pdo->prepare("UPDATE launch_roster SET deals_override=?, updated_at=datetime('now') WHERE id=?")->execute([$val, $id]);
    launch_roster_recalc_graduation($pdo);
    jok();
}

if ($action === 'log_deal') {
    $id   = (int)($body['id'] ?? 0);
    $date = trim($body['deal_date'] ?? '');
    if (!$id) jerr('id required');
    if ($date === '') $date = date('Y-m-d');
    $pdo->prepare("INSERT INTO launch_roster_deals (roster_id, deal_date, notes, created_by, created_at) VALUES (?, ?, ?, ?, datetime('now'))")
        ->execute([$id, $date, trim($body['notes'] ?? ''), strtolower(trim($agent['email'] ?? ''))]);
    launch_roster_recalc_graduation($pdo);
    jok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete_deal') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');
    $pdo->prepare("DELETE FROM launch_roster_deals WHERE id=?")->execute([$id]);
    jok();
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');
    $pdo->prepare("DELETE FROM launch_roster WHERE id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM launch_roster_deals WHERE roster_id=?")->execute([$id]);
    jok();
}

jerr('unknown action');
