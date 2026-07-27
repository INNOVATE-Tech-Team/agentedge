<?php
// Personal email signature API — any logged-in non-agent user.
// Actions: get, save, upload_photo.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

function sig_photo_url(string $photoKey): string {
    if ($photoKey === '') return '';
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com') . '/api/email_image.php?key=' . urlencode($photoKey);
}

function sig_img_dir(): string {
    $c   = cfg();
    $dir = ($c['local_db_dir'] ?? (__DIR__ . '/../data')) . '/email_images';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

$agent = current_agent();
if (!$agent) json_out(['ok' => false, 'error' => 'Not signed in'], 401);
if (in_array(my_role(), ['agent', 'launch_agent'], true)) {
    json_out(['ok' => false, 'error' => 'Forbidden'], 403);
}

$me = strtolower(trim($agent['email'] ?? ''));
$db = local_db();

// Photo upload is multipart — read action from POST field, not JSON body
$action = trim($_POST['action'] ?? '');
if ($action === '') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
} else {
    $body = [];
}

if ($action === 'get') {
    $st = $db->prepare("SELECT title, phone, calendar_url, website_url, use_custom, custom_html, photo_key FROM email_signatures WHERE email=?");
    $st->execute([$me]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'phone' => '', 'calendar_url' => '', 'website_url' => '', 'use_custom' => 0, 'custom_html' => '', 'photo_key' => ''];
    $row['use_custom'] = (bool)$row['use_custom'];
    $row['photo_url']  = sig_photo_url($row['photo_key'] ?? '');
    unset($row['photo_key']);
    json_out(array_merge(['ok' => true], $row));
}

if ($action === 'save') {
    $db->prepare(
        "INSERT INTO email_signatures (email, title, phone, calendar_url, website_url, use_custom, custom_html, updated_at)
         VALUES (?,?,?,?,?,?,?,datetime('now'))
         ON CONFLICT(email) DO UPDATE SET
           title=excluded.title, phone=excluded.phone, calendar_url=excluded.calendar_url,
           website_url=excluded.website_url, use_custom=excluded.use_custom,
           custom_html=excluded.custom_html, updated_at=excluded.updated_at"
    )->execute([
        $me,
        trim($body['title']        ?? ''),
        trim($body['phone']        ?? ''),
        trim($body['calendar_url'] ?? ''),
        trim($body['website_url']  ?? ''),
        empty($body['use_custom']) ? 0 : 1,
        trim($body['custom_html']  ?? ''),
    ]);
    json_out(['ok' => true]);
}

if ($action === 'upload_photo') {
    $file = $_FILES['photo'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'Upload failed — ' . ($file['error'] ?? 'no file')]);
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'Photo must be under 5 MB']);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        json_out(['ok' => false, 'error' => 'Only JPG, PNG, WebP, or GIF images are allowed']);
    }

    // Stable key per user — new upload replaces the old file in place when ext matches,
    // otherwise the old file is cleaned up explicitly.
    $ext    = $allowed[$mime];
    $newKey = md5('mysig:' . $me) . '.' . $ext;
    $imgDir = sig_img_dir();

    // Remove any old sig photo for this user (different extension)
    foreach (['jpg', 'png', 'webp', 'gif'] as $e) {
        if ($e === $ext) continue;
        $old = $imgDir . '/' . md5('mysig:' . $me) . '.' . $e;
        if (is_file($old)) @unlink($old);
    }

    if (!move_uploaded_file($file['tmp_name'], $imgDir . '/' . $newKey)) {
        json_out(['ok' => false, 'error' => 'Could not save photo']);
    }

    // Persist photo_key on the signature row
    $db->prepare(
        "INSERT INTO email_signatures (email, photo_key, updated_at)
         VALUES (?, ?, datetime('now'))
         ON CONFLICT(email) DO UPDATE SET photo_key=excluded.photo_key, updated_at=excluded.updated_at"
    )->execute([$me, $newKey]);

    json_out(['ok' => true, 'photo_url' => sig_photo_url($newKey)]);
}

if ($action === 'delete_photo') {
    $st = $db->prepare("SELECT photo_key FROM email_signatures WHERE email=?");
    $st->execute([$me]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $oldKey = $row['photo_key'] ?? '';
    if ($oldKey !== '') {
        $path = sig_img_dir() . '/' . $oldKey;
        if (is_file($path)) @unlink($path);
        $db->prepare("UPDATE email_signatures SET photo_key='', updated_at=datetime('now') WHERE email=?")->execute([$me]);
    }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
