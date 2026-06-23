<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent || !is_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$db = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query("SELECT * FROM custom_events ORDER BY start_date, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['featured'] = (bool)$r['featured'];
    echo json_encode(['events' => $rows]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

function ce_validate(array $d): ?string {
    if (empty(trim($d['name'] ?? '')))       return 'Event name is required.';
    if (empty(trim($d['start_date'] ?? ''))) return 'Start date is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start_date'])) return 'Start date must be YYYY-MM-DD.';
    if (!empty($d['end_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end_date'])) return 'End date must be YYYY-MM-DD.';
    return null;
}

function ce_sanitize(array $d): array {
    $cats = ['brokerage','leadership','nar','inman','training','technology','industry','finance'];
    return [
        'name'        => trim($d['name']        ?? ''),
        'organizer'   => trim($d['organizer']    ?? ''),
        'category'    => in_array($d['category'] ?? '', $cats) ? $d['category'] : 'industry',
        'start_date'  => trim($d['start_date']   ?? ''),
        'end_date'    => trim($d['end_date']      ?? ''),
        'location'    => trim($d['location']      ?? ''),
        'url'         => trim($d['url']           ?? ''),
        'description' => trim($d['description']  ?? ''),
        'featured'    => empty($d['featured']) ? 0 : 1,
    ];
}

if ($action === 'create') {
    if ($err = ce_validate($body)) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
    $s = ce_sanitize($body);
    $db->prepare(
        "INSERT INTO custom_events (name,organizer,category,start_date,end_date,location,url,description,featured,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([$s['name'],$s['organizer'],$s['category'],$s['start_date'],$s['end_date'],
                $s['location'],$s['url'],$s['description'],$s['featured'],$agent['email']]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id.']); exit; }
    if ($err = ce_validate($body)) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
    $s = ce_sanitize($body);
    $db->prepare(
        "UPDATE custom_events SET name=?,organizer=?,category=?,start_date=?,end_date=?,location=?,url=?,description=?,featured=?
         WHERE id=?"
    )->execute([$s['name'],$s['organizer'],$s['category'],$s['start_date'],$s['end_date'],
                $s['location'],$s['url'],$s['description'],$s['featured'],$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id.']); exit; }
    $db->prepare("DELETE FROM custom_events WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
