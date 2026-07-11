<?php
// Protected file download — streams a doc file from data/docs/.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';

$me = current_agent();
if (!$me) { header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db = local_db();
$f = $db->prepare(
    "SELECT f.*, COALESCE(fo.visibility,'all') as folder_vis
     FROM doc_files f
     LEFT JOIN doc_folders fo ON fo.id = f.folder_id
     WHERE f.id=?"
);
$f->execute([$id]);
$file = $f->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(404); echo 'Not found'; exit; }

// Check visibility
$vis = $file['folder_vis'];
$allowed = $vis === 'all'
    || ($vis === 'admin' && is_admin())
    || ($vis === 'leaders' && can_view_leader_docs());
if (!$allowed) { http_response_code(403); echo 'Forbidden'; exit; }

$path = __DIR__ . '/../data/docs/' . $file['storage_key'];
if (!file_exists($path)) { http_response_code(404); echo 'File not found'; exit; }

$mime = $file['mime_type'] ?: mime_content_type($path) ?: 'application/octet-stream';
$inlineTypes = ['application/pdf', 'text/html'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file['orig_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
