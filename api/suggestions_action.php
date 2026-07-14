<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent || (!can_post_announcements() && !is_recruiter())) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

$db      = local_db();
$myEmail = strtolower($agent['email'] ?? '');
$myName  = $agent['name'] ?: $myEmail;
$isAdmin = is_admin();

// ── GET: list all suggestions with caller's vote status ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query(
        "SELECT * FROM suggestions ORDER BY upvotes DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $voted    = [];
    $filesBy  = [];
    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        $vrows = $db->query(
            "SELECT suggestion_id FROM suggestion_votes WHERE email=" . $db->quote($myEmail) .
            " AND suggestion_id IN ($ids)"
        )->fetchAll(PDO::FETCH_COLUMN);
        $voted = array_flip($vrows);

        $frows = $db->query(
            "SELECT id,suggestion_id,orig_name,mime_type,size_bytes,uploaded_by,created_at
             FROM suggestion_files WHERE suggestion_id IN ($ids) ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($frows as $fr) {
            $filesBy[$fr['suggestion_id']][] = $fr;
        }
    }

    foreach ($rows as &$r) {
        $r['upvotes'] = (int)$r['upvotes'];
        $r['my_vote'] = isset($voted[$r['id']]);
        $r['files']   = $filesBy[$r['id']] ?? [];
    }
    echo json_encode(['suggestions' => $rows]);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// CSRF check for all mutating actions
if (!in_array($action, ['create','vote','update_status','delete','edit'], true)) {
    echo json_encode(['ok'=>false,'error'=>'Unknown action.']); exit;
}
$sessionCsrf = $_SESSION['csrf'] ?? '';
if (!hash_equals($sessionCsrf, (string)($body['csrf'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF.']); exit;
}

// ── create ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title = trim($body['title'] ?? '');
    if ($title === '') { echo json_encode(['ok'=>false,'error'=>'Title is required.']); exit; }
    $title = substr($title, 0, 160);

    $cats  = ['general','technology','training','culture','recruiting','operations','marketing'];
    $cat   = in_array($body['category'] ?? '', $cats) ? $body['category'] : 'general';
    $bodyT = trim($body['body'] ?? '');

    $now = gmdate('Y-m-d H:i:s');
    $db->prepare(
        "INSERT INTO suggestions (submitted_by,submitter_name,category,title,body,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$myEmail, $myName, $cat, $title, $bodyT, $now, $now]);
    $suggestionId = (int)$db->lastInsertId();

    notify_suggestion_created($suggestionId, $title, $bodyT, $cat, $myName, $myEmail);

    echo json_encode(['ok'=>true, 'id'=>$suggestionId]);
    dispatch_notification_queue();
    exit;
}

// ── edit (owner or admin) ──────────────────────────────────────────────────────
if ($action === 'edit') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id.']); exit; }

    $row = $db->prepare("SELECT submitted_by FROM suggestions WHERE id=?");
    $row->execute([$id]);
    $owner = $row->fetchColumn();
    if ($owner === false) { echo json_encode(['ok'=>false,'error'=>'Not found.']); exit; }
    if (!$isAdmin && strtolower((string)$owner) !== $myEmail) {
        echo json_encode(['ok'=>false,'error'=>'You can only edit your own suggestions.']); exit;
    }

    $title = trim($body['title'] ?? '');
    if ($title === '') { echo json_encode(['ok'=>false,'error'=>'Title is required.']); exit; }
    $title = substr($title, 0, 160);

    $cats  = ['general','technology','training','culture','recruiting','operations','marketing'];
    $cat   = in_array($body['category'] ?? '', $cats) ? $body['category'] : 'general';
    $bodyT = trim($body['body'] ?? '');
    $now   = gmdate('Y-m-d H:i:s');

    $db->prepare("UPDATE suggestions SET title=?,category=?,body=?,updated_at=? WHERE id=?")
       ->execute([$title, $cat, $bodyT, $now, $id]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── vote ──────────────────────────────────────────────────────────────────────
if ($action === 'vote') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id.']); exit; }

    $existing = $db->prepare("SELECT 1 FROM suggestion_votes WHERE suggestion_id=? AND email=?");
    $existing->execute([$id, $myEmail]);
    $hasVote = (bool)$existing->fetchColumn();

    if ($hasVote) {
        $db->prepare("DELETE FROM suggestion_votes WHERE suggestion_id=? AND email=?")->execute([$id, $myEmail]);
        $db->prepare("UPDATE suggestions SET upvotes=MAX(0,upvotes-1) WHERE id=?")->execute([$id]);
        $voted = false;
    } else {
        $db->prepare("INSERT OR IGNORE INTO suggestion_votes (suggestion_id,email) VALUES (?,?)")->execute([$id, $myEmail]);
        $db->prepare("UPDATE suggestions SET upvotes=upvotes+1 WHERE id=?")->execute([$id]);
        $voted = true;
    }

    $upvotes = (int)$db->query("SELECT upvotes FROM suggestions WHERE id=" . (int)$id)->fetchColumn();
    echo json_encode(['ok'=>true, 'voted'=>$voted, 'upvotes'=>$upvotes]);
    exit;
}

// ── update_status (admin only) ────────────────────────────────────────────────
if ($action === 'update_status') {
    if (!$isAdmin) { echo json_encode(['ok'=>false,'error'=>'Admin only.']); exit; }
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id.']); exit; }

    $statuses = ['pending','under_review','implemented','declined'];
    $status   = in_array($body['status'] ?? '', $statuses) ? $body['status'] : 'pending';
    $note     = trim($body['admin_note'] ?? '');
    $now      = gmdate('Y-m-d H:i:s');

    $db->prepare("UPDATE suggestions SET status=?,admin_note=?,updated_at=? WHERE id=?")
       ->execute([$status, $note, $now, $id]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id.']); exit; }

    // Allow admins to delete anything; others can only delete their own
    if (!$isAdmin) {
        $row = $db->prepare("SELECT submitted_by FROM suggestions WHERE id=?");
        $row->execute([$id]);
        $own = $row->fetchColumn();
        if (strtolower((string)$own) !== $myEmail) {
            echo json_encode(['ok'=>false,'error'=>'You can only delete your own suggestions.']); exit;
        }
    }

    $db->prepare("DELETE FROM suggestions WHERE id=?")->execute([$id]);
    $db->prepare("DELETE FROM suggestion_votes WHERE suggestion_id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
