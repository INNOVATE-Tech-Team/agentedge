<?php
// Protected download — streams a ticket attachment from data/ticket_uploads/.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

$me = current_agent();
if (!$me) { http_response_code(401); echo 'Not signed in'; exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db = local_db();
$f = $db->prepare("SELECT * FROM support_ticket_files WHERE id=?");
$f->execute([$id]);
$file = $f->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(404); echo 'Not found'; exit; }

if (!is_admin()) {
    $ts = $db->prepare("SELECT agent_email FROM support_tickets WHERE id=?");
    $ts->execute([(int)$file['ticket_id']]);
    $ownerEmail = strtolower((string)$ts->fetchColumn());
    if ($ownerEmail !== strtolower($me['email'])) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
}

$path = __DIR__ . '/../data/ticket_uploads/' . $file['storage_key'];
if (!file_exists($path)) { http_response_code(404); echo 'File not found'; exit; }

$mime = $file['mime_type'] ?: (mime_content_type($path) ?: 'application/octet-stream');
$inlineTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file['orig_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
