<?php
// Per-agent Documents tab (agent_profile.php) — admin only.
// GET  ?email=...            → list documents for one agent
// GET  action=download&key=  → serve a document file
// POST action=upload         → manual upload (multipart/form-data, fields: email, file)
// POST action=delete         → body: { key }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

$agent = current_agent();
if (!$agent) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'admin only']); exit; }

$pdo    = local_db();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

const DOC_CATEGORIES = ['license', 'e_and_o', 'mls_paperwork', 'ce_credit', 'onboarding', 'other'];

function agent_documents_dir(): string {
    $cfgDir = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    return ($cfgDir ?: (__DIR__ . '/../data')) . '/agent_documents';
}

// ── GET: serve a document file ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $key = trim($_GET['key'] ?? '');
    if (!$key || !preg_match('/^[a-f0-9]+\.[a-z0-9]{2,5}$/i', $key)) {
        header('Content-Type: application/json'); echo json_encode(['error' => 'invalid key']); exit;
    }
    $st = $pdo->prepare("SELECT name, mime_type FROM agent_documents WHERE storage_key=?");
    $st->execute([$key]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { header('Content-Type: application/json'); http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $path = agent_documents_dir() . '/' . basename($key);
    if (!file_exists($path)) { header('Content-Type: application/json'); http_response_code(404); echo json_encode(['error' => 'file not found']); exit; }

    header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . addslashes($doc['name']) . '"');
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

header('Content-Type: application/json');

// ── GET: list documents for one agent ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if ($email === '') { echo json_encode(['error' => 'email required']); exit; }
    $st = $pdo->prepare(
        "SELECT id, name, source, category, mime_type, size_bytes, storage_key, uploaded_by, created_at
         FROM agent_documents WHERE email=? ORDER BY created_at DESC"
    );
    $st->execute([$email]);
    echo json_encode(['ok' => true, 'documents' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

// ── POST: manual upload ───────────────────────────────────────────────────────
if ($action === 'upload') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '') { echo json_encode(['ok' => false, 'error' => 'email required']); exit; }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No valid file received']); exit;
    }
    $f = $_FILES['file'];
    if ($f['size'] > 20 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File exceeds 20 MB limit']); exit;
    }
    $mime = mime_content_type($f['tmp_name']) ?: 'application/octet-stream';

    $dir = agent_documents_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'bin';
    $key = bin2hex(random_bytes(16)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext ?: 'bin');
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $key)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']); exit;
    }

    $name = trim($_POST['name'] ?? '') ?: basename($f['name']);
    $category = in_array($_POST['category'] ?? '', DOC_CATEGORIES, true) ? $_POST['category'] : 'other';
    $uploadedBy = strtolower(trim($agent['email'] ?? ''));
    $pdo->prepare(
        "INSERT INTO agent_documents (email, name, source, category, external_ref, mime_type, size_bytes, storage_key, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([$email, $name, 'manual', $category, '', $mime, $f['size'], $key, $uploadedBy]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

// ── POST: delete ──────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $key  = trim($body['key'] ?? '');
    if (!$key) { echo json_encode(['ok' => false, 'error' => 'key required']); exit; }
    @unlink(agent_documents_dir() . '/' . basename($key));
    $pdo->prepare("DELETE FROM agent_documents WHERE storage_key=?")->execute([$key]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
