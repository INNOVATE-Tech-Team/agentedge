<?php
// Company Email attachment upload/delete. Files are uploaded immediately on
// pick (before the parent send/schedule row exists) and referenced by a
// random token; api/company_email_action.php resolves tokens -> ids at
// send/schedule time. Unlike email_image.php these are never served by URL —
// they're read from disk and base64-embedded into the SendGrid payload
// (see resolve_email_attachments() in lib/notifications.php).
// ob_start/ini_set/ob_clean: this container has display_errors=STDOUT, so any
// stray PHP notice/warning from an included file would otherwise get printed
// straight into the response body ahead of the JSON, breaking the client's
// response.json() parse (surfaces to the user as a generic "Network error").
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!can_send_company_email()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$me = strtolower(trim($agent['email'] ?? ''));

function ea_dir(): string {
    $c = cfg();
    return ($c['local_db_dir'] ?? (__DIR__ . '/../data')) . '/email_attachments';
}

if (!empty($_FILES['file'])) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit; }
    if ($file['size'] > 20 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'File must be under 20 MB']); exit; }

    $allowed = [
        'application/pdf'                                                          => 'pdf',
        'application/msword'                                                       => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
        'application/vnd.ms-excel'                                                 => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
        'application/vnd.ms-powerpoint'                                            => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'=> 'pptx',
        'text/plain'  => 'txt',
        'text/csv'    => 'csv',
        'image/jpeg'  => 'jpg',
        'image/png'   => 'png',
        'image/gif'   => 'gif',
    ];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) { echo json_encode(['ok'=>false,'error'=>'That file type is not supported']); exit; }

    $ext        = $allowed[$mime];
    $storageKey = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest       = ea_dir() . '/' . $storageKey;
    if (!move_uploaded_file($file['tmp_name'], $dest)) { echo json_encode(['ok'=>false,'error'=>'Could not save file']); exit; }

    $origName = $file['name'] !== '' ? $file['name'] : $storageKey;
    $token    = bin2hex(random_bytes(16));

    local_db()->prepare(
        "INSERT INTO email_attachments (token, owner_email, orig_name, mime_type, size_bytes, storage_key)
         VALUES (?,?,?,?,?,?)"
    )->execute([$token, $me, $origName, $mime, $file['size'], $storageKey]);

    echo json_encode(['ok'=>true, 'token'=>$token, 'name'=>$origName, 'size'=>(int)$file['size']]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'delete') {
    $token = trim($body['token'] ?? '');
    if (!$token) { echo json_encode(['ok'=>false,'error'=>'token required']); exit; }

    $db  = local_db();
    $stmt = $db->prepare("SELECT id, owner_email, storage_key FROM email_attachments WHERE token=?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>true]); exit; } // already gone
    if ($row['owner_email'] !== $me && !is_admin()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

    @unlink(ea_dir() . '/' . $row['storage_key']);
    $db->prepare("DELETE FROM email_attachments WHERE id=?")->execute([$row['id']]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
