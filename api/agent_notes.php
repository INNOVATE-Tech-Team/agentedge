<?php
// Free-form staff notes about an agent — Notes tab on agent_profile.php.
// GET  ?email=...  → admin only: list notes for one agent, newest first
// POST             → admin only: add a note for body.email
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }

$pdo = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }
    $st = $pdo->prepare("SELECT id, note, created_by, created_at FROM agent_notes WHERE email=? ORDER BY created_at DESC, id DESC");
    $st->execute([$email]);
    echo json_encode(['ok' => true, 'notes' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($body['email'] ?? ''));
$note  = trim($body['note'] ?? '');
if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }
if ($note === '')  { http_response_code(400); echo json_encode(['error' => 'note required']); exit; }

$createdBy = strtolower(trim($agent['email'] ?? ''));
$pdo->prepare(
    "INSERT INTO agent_notes (email, note, created_by, created_at) VALUES (?, ?, ?, datetime('now'))"
)->execute([$email, $note, $createdBy]);

echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'created_by' => $createdBy]);
