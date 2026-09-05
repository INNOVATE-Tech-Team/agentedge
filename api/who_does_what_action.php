<?php
// Back Office editor's API for team_directory (Back Office → Technology →
// Who Does What). Also serves directory photos (action=photo) to any signed-in
// agent, since — unlike the private intake headshot proxy in api/intake.php,
// which only serves an agent their own photo or a leader anyone's — this
// directory is meant to be visible company-wide.
//
// GET  action=photo&key=        → serve a directory photo (any signed-in agent)
// POST action=upload_photo      → upload a directory photo (multipart, field: photo; admin only)
// POST action=save              → upsert a person (JSON body; admin only)
// POST action=toggle            → flip active/inactive by id (JSON body; admin only)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/who_does_what.php';

$agent = require_login();
$pdo   = local_db();
// 'save'/'toggle' arrive as a JSON body with no ?action= query string (see
// admin_who_does_what.php's post() helper), so $action must also check the
// decoded body — $_GET/$_POST alone stay empty for those two actions and
// every save/toggle request silently fell through to "Unknown action".
$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($_POST['action'] ?? ($in['action'] ?? ''));

function wdw_data_dir(): string {
    $cfgDir = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    return ($cfgDir ?: (__DIR__ . '/../data')) . '/team_directory_photos';
}

// ── GET: serve a directory photo — any signed-in agent may view it ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'photo') {
    $key = trim($_GET['key'] ?? '');
    if (!$key || !preg_match('/^[a-f0-9]+\.[a-z0-9]{2,5}$/i', $key)) {
        http_response_code(400); exit;
    }
    $st = $pdo->prepare("SELECT 1 FROM team_directory WHERE photo_key = ?");
    $st->execute([$key]);
    if (!$st->fetchColumn()) { http_response_code(404); exit; }

    $path = wdw_data_dir() . '/' . basename($key);
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Everything past this point is Back Office only.
header('Content-Type: application/json');
if (!is_admin()) { echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit; }

// Explicit CSRF check (not just same-origin fetch) -- matches the
// admin_work_item_action.php/ticket_file_action.php convention. upload_photo
// arrives as multipart (token in $_POST), save/toggle as a JSON body (token
// already decoded into $in above).
$csrfToken = $_POST['csrf'] ?? ($in['csrf'] ?? '');
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$csrfToken)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']); exit;
}

// ── POST: upload a directory photo (multipart) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_photo') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No photo uploaded']); exit;
    }
    $f = $_FILES['photo'];
    if ($f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Photo must be under 5MB']); exit;
    }
    $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    if (!isset($mimeExt[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'Photo must be a JPG, PNG, WEBP, or GIF']); exit;
    }
    $dir = wdw_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $key = bin2hex(random_bytes(12)) . '.' . $mimeExt[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $key)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save photo']); exit;
    }
    echo json_encode(['ok' => true, 'photo_key' => $key]);
    exit;
}

// ── POST: add or edit a person ──────────────────────────────────────────────
if ($action === 'save') {
    $email = strtolower(trim($in['email'] ?? ''));
    $name  = trim($in['name'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'A valid email is required']); exit;
    }
    if (!$name) { echo json_encode(['ok' => false, 'error' => 'Name is required']); exit; }

    // group_label holds one or more WDW_GROUPS values, comma-separated --
    // same convention as tags below (see lib/who_does_what.php). Accept a
    // bare string too (e.g. a client mid-deploy still sending the old
    // single-select shape) rather than only an array -- wdw_groups_encode
    // still validates against WDW_GROUPS either way, so this doesn't accept
    // anything invalid, it just tolerates one more valid input shape.
    $groupsIn = $in['group_label'] ?? null;
    if (is_string($groupsIn)) $groupsIn = ($groupsIn !== '') ? [$groupsIn] : [];
    $groups = is_array($groupsIn) ? wdw_groups_encode($groupsIn) : '';
    if ($groups === '') {
        echo json_encode(['ok' => false, 'error' => 'Select at least one group (Leadership, Admins, or Brokers)']); exit;
    }

    $bookingUrl = trim($in['booking_url'] ?? '');
    if ($bookingUrl !== '') {
        if (!filter_var($bookingUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $bookingUrl)) {
            echo json_encode(['ok' => false, 'error' => 'Booking URL must be a valid http(s) link']); exit;
        }
    }

    $photoKey = trim($in['photo_key'] ?? '');
    if ($photoKey !== '' && !preg_match('/^[a-f0-9]+\.[a-z0-9]{2,5}$/i', $photoKey)) {
        $photoKey = ''; // ignore anything that didn't come from upload_photo
    }

    $tags    = is_array($in['tags'] ?? null) ? wdw_tags_encode($in['tags']) : '';
    $title   = trim($in['title'] ?? '');
    $handles = trim($in['handles'] ?? '');
    $phone   = trim($in['phone'] ?? '');
    $sortOrd = (int)($in['sort_ord'] ?? 0);
    $enabled = !empty($in['enabled']) ? 1 : 0;
    $id      = (int)($in['id'] ?? 0);

    try {
        if ($id) {
            $pdo->prepare(
                "UPDATE team_directory
                 SET email=?, name=?, title=?, group_label=?, handles=?, tags=?, phone=?,
                     booking_url=?, photo_key=?, enabled=?, sort_ord=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$email, $name, $title, $groups, $handles, $tags, $phone, $bookingUrl, $photoKey, $enabled, $sortOrd, $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO team_directory
                 (email, name, title, group_label, handles, tags, phone, booking_url, photo_key, enabled, sort_ord)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$email, $name, $title, $groups, $handles, $tags, $phone, $bookingUrl, $photoKey, $enabled, $sortOrd]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (\PDOException $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            echo json_encode(['ok' => false, 'error' => 'That email is already in the directory — edit their existing entry instead.']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Save failed']);
        }
        exit;
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── POST: flip active/inactive ──────────────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Person required']); exit; }
    $pdo->prepare("UPDATE team_directory SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END, updated_at=datetime('now') WHERE id=?")->execute([$id]);
    $s = $pdo->prepare("SELECT enabled FROM team_directory WHERE id=?");
    $s->execute([$id]);
    echo json_encode(['ok' => true, 'enabled' => (int)$s->fetchColumn()]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
