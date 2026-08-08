<?php
// LAUNCH Curriculum content editing — launch_session.php.
// POST {action:'update_session', session_number, title, theme_quote, the_goal, primary_jobs, content_md} → can_view_launch_curriculum()
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
if (!can_view_launch_curriculum()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not authorized']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }

$pdo    = local_db();
$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($action === 'update_framework') {
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') jerr('title required');
    $pdo->prepare("UPDATE launch_framework SET title=?, content_md=?, updated_by=?, updated_at=datetime('now') WHERE id=1")
        ->execute([$title, (string)($body['content_md'] ?? ''), strtolower(trim($agent['email'] ?? ''))]);
    jok();
}

if ($action !== 'update_session') jerr('unknown action');

$sessionNumber = (int)($body['session_number'] ?? 0);
if ($sessionNumber < 1) jerr('session_number required');

$st = $pdo->prepare("SELECT id FROM launch_sessions WHERE session_number=?");
$st->execute([$sessionNumber]);
if (!$st->fetchColumn()) jerr('session not found', 404);

$fv = fn($k) => trim((string)($body[$k] ?? ''));
$pdo->prepare(
    "UPDATE launch_sessions SET title=?, theme_quote=?, the_goal=?, primary_jobs=?, content_md=?, updated_by=?, updated_at=datetime('now') WHERE session_number=?"
)->execute([
    $fv('title'), $fv('theme_quote'), $fv('the_goal'), $fv('primary_jobs'), (string)($body['content_md'] ?? ''),
    strtolower(trim($agent['email'] ?? '')), $sessionNumber,
]);

jok();
