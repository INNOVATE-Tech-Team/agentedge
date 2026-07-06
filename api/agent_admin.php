<?php
// Staff-managed agent fields — set by admin/HR, not self-reported on the intake
// form (1099 classification, team/coach/manager assignment, termination date).
// GET  ?email=...  → admin only: load one agent's agent_admin row
// POST             → admin only: upsert agent_admin fields for body.email
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
    $st = $pdo->prepare("SELECT * FROM agent_admin WHERE email=?");
    $st->execute([$email]);
    echo json_encode(['ok' => true, 'admin' => $st->fetch(PDO::FETCH_ASSOC) ?: []]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($body['email'] ?? ''));
if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }

$fv = fn($k) => trim($body[$k] ?? '');
$now = date('Y-m-d H:i:s');
$adminEmail = strtolower(trim($agent['email'] ?? ''));

$pdo->prepare(
    "INSERT INTO agent_admin
        (email, tax_1099_type, gets_1099, terminated_date, agent_team, coached_by, managed_by, updated_by, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?)
     ON CONFLICT(email) DO UPDATE SET
        tax_1099_type   = excluded.tax_1099_type,
        gets_1099       = excluded.gets_1099,
        terminated_date = excluded.terminated_date,
        agent_team      = excluded.agent_team,
        coached_by      = excluded.coached_by,
        managed_by      = excluded.managed_by,
        updated_by      = excluded.updated_by,
        updated_at      = excluded.updated_at"
)->execute([
    $email,
    $fv('tax_1099_type'),
    !empty($body['gets_1099']) ? 1 : 0,
    $fv('terminated_date'),
    $fv('agent_team'),
    $fv('coached_by'),
    $fv('managed_by'),
    $adminEmail,
    $now,
]);

echo json_encode(['ok' => true]);
