<?php
// Agent-facing Polls API.
//
// POST action=submit    {csrf, slug, answers}  -> save the caller's one response
// GET  action=aggregate &slug=                 -> totals + counts ONLY, and only
//                                                 after the caller has responded
//
// Nothing here ever returns a name, email or user id. Per-person answers are
// only available through api/admin_polls_action.php (super admin).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/polls.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in.']); exit; }

function polls_json(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }

$pdo      = local_db();
$in       = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $_GET['action'] ?? ($in['action'] ?? '');
$slug     = trim((string)($_GET['slug'] ?? ($in['slug'] ?? '')));
$identity = polls_identity($pdo, $agent);
if (!$identity) polls_json(['ok' => false, 'error' => 'Your account has no email address.'], 400);

$poll = $slug !== '' ? polls_load($pdo, $slug) : null;
// Agents never learn that a draft exists. Responses are always keyed to the
// poll's real status, so a draft/closed poll simply 404s here.
if (!$poll || $poll['status'] === 'draft') polls_json(['ok' => false, 'error' => 'Poll not found.'], 404);

if ($action === 'aggregate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $agg = polls_public_aggregate($pdo, $poll, $identity);
    if ($agg === null) polls_json(['ok' => false, 'error' => 'Submit your answers to see results.'], 403);
    polls_json(['ok' => true, 'results' => $agg]);
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
        polls_json(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }
    // A super admin "logged in as" an agent may preview but must never vote
    // on that agent's behalf.
    if (is_masquerading()) {
        polls_json(['ok' => false, 'code' => 'preview', 'error' => 'Preview only, not saving.'], 403);
    }
    $r = polls_submit($pdo, $poll, $identity, $in['answers'] ?? null);
    if (!$r['ok']) {
        polls_json(['ok' => false, 'code' => $r['code'], 'error' => $r['error']], $r['code'] === 'invalid' ? 422 : 409);
    }
    polls_json(['ok' => true]);
}

polls_json(['ok' => false, 'error' => 'Unknown action'], 400);
