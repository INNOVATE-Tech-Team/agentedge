<?php
// Free-form staff notes about an agent — never visible to the agent
// themselves. Used by agent_profile.php's Notes tab, backoffice_agents.php's
// detail row, and onboarding.php's queue entries.
// GET  ?email=...  → admin/bic/mc_leader: list notes for one agent, newest first
// POST             → admin/bic/mc_leader: add a note for body.email
// mc_leader/bic are scoped to agents in their own Market Center(s) only.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$isAdmin  = is_admin();
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { http_response_code(403); echo json_encode(['error' => 'admin/leader only']); exit; }

$pdo = local_db();

// The Market Center slug(s) this agent (by email) is associated with — checked
// against the caller's my_mc_slugs() for non-admins. Mirrors the same
// roster-then-office_location fallback used in backoffice_agents.php.
function agent_notes_mc_slugs(PDO $pdo, string $email): array {
    $slugs = [];
    $st = $pdo->prepare("SELECT market_center FROM innovate_roster WHERE LOWER(TRIM(email))=? AND active=1");
    $st->execute([$email]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mc) $slugs[] = slugify_mc($mc ?: '');
    if (!$slugs) {
        $st2 = $pdo->prepare("SELECT office_location FROM agent_intake WHERE email=?");
        $st2->execute([$email]);
        $office = $st2->fetchColumn();
        if ($office) $slugs[] = slugify_mc($office);
    }
    return array_filter($slugs);
}

function agent_notes_in_scope(PDO $pdo, string $email, bool $isAdmin): bool {
    if ($isAdmin) return true;
    return (bool)array_intersect(agent_notes_mc_slugs($pdo, $email), my_mc_slugs());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }
    if (!agent_notes_in_scope($pdo, $email, $isAdmin)) {
        http_response_code(403); echo json_encode(['error' => 'not in your Market Center']); exit;
    }
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
if (!agent_notes_in_scope($pdo, $email, $isAdmin)) {
    http_response_code(403); echo json_encode(['error' => 'not in your Market Center']); exit;
}

$createdBy = strtolower(trim($agent['email'] ?? ''));
$pdo->prepare(
    "INSERT INTO agent_notes (email, note, created_by, created_at) VALUES (?, ?, ?, datetime('now'))"
)->execute([$email, $note, $createdBy]);

echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'created_by' => $createdBy]);
