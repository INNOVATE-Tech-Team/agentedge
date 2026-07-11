<?php
// Protected download — streams a suggestion attachment from data/suggestion_uploads/.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

$agent = current_agent();
if (!$agent || (!can_post_announcements() && !is_recruiter())) {
    http_response_code(403); echo 'Forbidden'; exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db = local_db();
$f = $db->prepare("SELECT * FROM suggestion_files WHERE id=?");
$f->execute([$id]);
$file = $f->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(404); echo 'Not found'; exit; }

$path = __DIR__ . '/../data/suggestion_uploads/' . $file['storage_key'];
if (!file_exists($path)) { http_response_code(404); echo 'File not found'; exit; }

$mime = $file['mime_type'] ?: (mime_content_type($path) ?: 'application/octet-stream');
$inlineTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file['orig_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
