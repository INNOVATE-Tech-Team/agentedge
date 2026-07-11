<?php
// Suggestion attachments: upload / delete files linked to a suggestion.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent || (!can_post_announcements() && !is_recruiter())) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

$db      = local_db();
$myEmail = strtolower($agent['email'] ?? '');
$isAdmin = is_admin();

function suggestion_owner_email(PDO $db, int $id): ?string {
    $st = $db->prepare("SELECT submitted_by FROM suggestions WHERE id=?");
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : strtolower((string)$v);
}

// ── Upload — multipart/form-data ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $sessionCsrf = $_SESSION['csrf'] ?? '';
    if (!hash_equals($sessionCsrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF.']); exit;
    }

    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    $owner = $suggestionId ? suggestion_owner_email($db, $suggestionId) : null;
    if ($owner === null) { echo json_encode(['ok'=>false,'error'=>'Suggestion not found.']); exit; }
    if (!$isAdmin && $owner !== $myEmail) {
        echo json_encode(['ok'=>false,'error'=>'You can only attach files to your own suggestions.']); exit;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Upload error.']); exit; }
    if ($file['size'] > 10 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'Max file size is 10 MB.']); exit; }

    $count = (int)$db->query("SELECT COUNT(*) FROM suggestion_files WHERE suggestion_id=" . $suggestionId)->fetchColumn();
    if ($count >= 8) { echo json_encode(['ok'=>false,'error'=>'Max 8 attachments per suggestion.']); exit; }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key  = uniqid('', true) . ($ext ? ".$ext" : '');
    $dest = __DIR__ . '/../data/suggestion_uploads/' . $key;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Save failed.']); exit;
    }

    $db->prepare(
        "INSERT INTO suggestion_files (suggestion_id,orig_name,mime_type,size_bytes,storage_key,uploaded_by)
         VALUES (?,?,?,?,?,?)"
    )->execute([$suggestionId, $file['name'], $file['type'], $file['size'], $key, $myEmail]);

    echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
    exit;
}

// ── Delete — JSON body ────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'delete_file') {
    $sessionCsrf = $_SESSION['csrf'] ?? '';
    if (!hash_equals($sessionCsrf, (string)($body['csrf'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF.']); exit;
    }

    $id = (int)($body['id'] ?? 0);
    $f = $db->prepare("SELECT suggestion_id, storage_key FROM suggestion_files WHERE id=?");
    $f->execute([$id]);
    $row = $f->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found.']); exit; }

    $owner = suggestion_owner_email($db, (int)$row['suggestion_id']);
    if (!$isAdmin && $owner !== $myEmail) {
        echo json_encode(['ok'=>false,'error'=>'You can only remove files from your own suggestions.']); exit;
    }

    $path = __DIR__ . '/../data/suggestion_uploads/' . $row['storage_key'];
    if (file_exists($path)) @unlink($path);
    $db->prepare("DELETE FROM suggestion_files WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
