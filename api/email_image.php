<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

function ei_dir(): string {
    $c   = cfg();
    $dir = ($c['local_db_dir'] ?? (__DIR__ . '/../data')) . '/email_images';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

// Serving is public (no auth) — recipients' email clients load <img src> with
// no cookies. The key is a random hex string, so this isn't guessable, but it
// is regex-validated to prevent path traversal.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = $_GET['key'] ?? '';
    if (!preg_match('/^[a-f0-9]+\.[a-z]{2,5}$/', $key)) { http_response_code(400); exit('Bad key'); }
    $path = ei_dir() . '/' . $key;
    if (!is_file($path)) { http_response_code(404); exit('Not found'); }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
    exit;
}

// Upload requires a signed-in agent who can send Company Email.
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!can_send_company_email()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$file = $_FILES['image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit; }
if ($file['size'] > 8 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'Image must be under 8 MB']); exit; }

$allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
$mime = mime_content_type($file['tmp_name']);
if (!isset($allowed[$mime])) { echo json_encode(['ok'=>false,'error'=>'Only JPG, PNG, GIF, or WebP images are allowed']); exit; }

$key  = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
$dest = ei_dir() . '/' . $key;
if (!move_uploaded_file($file['tmp_name'], $dest)) { echo json_encode(['ok'=>false,'error'=>'Could not save image']); exit; }

$url = 'https://' . $_SERVER['HTTP_HOST'] . '/api/email_image.php?key=' . urlencode($key);
echo json_encode(['ok'=>true, 'url'=>$url]);
