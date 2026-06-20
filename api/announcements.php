<?php
// GET  — returns active announcements for the dashboard widget or full listing.
// POST — admin creates/updates/deletes an announcement.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $adminOnly = !is_admin() ? "AND audience='all'" : '';
    $rows = local_db()->query(
        "SELECT id,title,body,author,audience,pinned,created_at,expires_at
         FROM announcements
         WHERE (expires_at IS NULL OR expires_at >= datetime('now'))
         $adminOnly
         ORDER BY pinned DESC, created_at DESC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'items'=>$rows]);
    exit;
}

// POST — admin only
if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? 'create';

if ($action === 'create' || $action === 'update') {
    $title   = trim($in['title'] ?? '');
    $body    = trim($in['body']  ?? '');
    if (!$title || !$body) { http_response_code(400); echo json_encode(['error'=>'title and body required']); exit; }
    $audience  = in_array($in['audience'] ?? 'all', ['all','admin']) ? ($in['audience']??'all') : 'all';
    $pinned    = empty($in['pinned']) ? 0 : 1;
    $expires   = !empty($in['expires_at']) ? $in['expires_at'] : null;
    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);
        $s = local_db()->prepare("UPDATE announcements SET title=?,body=?,audience=?,pinned=?,expires_at=? WHERE id=?");
        $s->execute([$title,$body,$audience,$pinned,$expires,$id]);
    } else {
        $s = local_db()->prepare("INSERT INTO announcements (title,body,author,audience,pinned,expires_at) VALUES (?,?,?,?,?,?)");
        $s->execute([$title,$body,$me['email'],$audience,$pinned,$expires]);
        $in['id'] = local_db()->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>(int)$in['id']]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    local_db()->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'pin') {
    $id  = (int)($in['id'] ?? 0);
    $val = empty($in['pinned']) ? 0 : 1;
    local_db()->prepare("UPDATE announcements SET pinned=? WHERE id=?")->execute([$val,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
