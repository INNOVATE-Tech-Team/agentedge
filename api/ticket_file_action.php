<?php
// Ticket attachments: upload / delete files linked to a ticket message.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not signed in']); exit; }

$db      = local_db();
$isAdmin = is_admin();

function ticket_file_owner_email(PDO $db, int $ticketId): ?string {
    $st = $db->prepare("SELECT agent_email FROM support_tickets WHERE id=?");
    $st->execute([$ticketId]);
    $v = $st->fetchColumn();
    return $v === false ? null : strtolower((string)$v);
}

// ── Upload — multipart/form-data ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $sessionCsrf = $_SESSION['csrf'] ?? '';
    if (!hash_equals($sessionCsrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF.']); exit;
    }

    $messageId = (int)($_POST['message_id'] ?? 0);
    $ms = $db->prepare("SELECT ticket_id FROM support_ticket_messages WHERE id=?");
    $ms->execute([$messageId]);
    $ticketId = $ms->fetchColumn();
    if ($ticketId === false) { echo json_encode(['ok'=>false,'error'=>'Message not found.']); exit; }
    $ticketId = (int)$ticketId;

    $owner = ticket_file_owner_email($db, $ticketId);
    if (!$isAdmin && $owner !== strtolower($me['email'])) {
        echo json_encode(['ok'=>false,'error'=>'You can only attach files to your own tickets.']); exit;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Upload error.']); exit; }
    if ($file['size'] > 10 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'Max file size is 10 MB.']); exit; }

    $count = (int)$db->query("SELECT COUNT(*) FROM support_ticket_files WHERE ticket_id=" . $ticketId)->fetchColumn();
    if ($count >= 8) { echo json_encode(['ok'=>false,'error'=>'Max 8 attachments per ticket.']); exit; }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key  = uniqid('', true) . ($ext ? ".$ext" : '');
    $dest = __DIR__ . '/../data/ticket_uploads/' . $key;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Save failed.']); exit;
    }

    $db->prepare(
        "INSERT INTO support_ticket_files (ticket_id,message_id,orig_name,mime_type,size_bytes,storage_key,uploaded_by)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$ticketId, $messageId, $file['name'], $file['type'], $file['size'], $key, strtolower($me['email'])]);

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
    $f = $db->prepare("SELECT ticket_id, storage_key FROM support_ticket_files WHERE id=?");
    $f->execute([$id]);
    $row = $f->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found.']); exit; }

    $owner = ticket_file_owner_email($db, (int)$row['ticket_id']);
    if (!$isAdmin && $owner !== strtolower($me['email'])) {
        echo json_encode(['ok'=>false,'error'=>'You can only remove files from your own tickets.']); exit;
    }

    $path = __DIR__ . '/../data/ticket_uploads/' . $row['storage_key'];
    if (file_exists($path)) @unlink($path);
    $db->prepare("DELETE FROM support_ticket_files WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
