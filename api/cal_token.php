<?php
// Manage the agent's personal calendar feed token.
// GET  → {ok, token, feed_url}   creates one if missing
// POST {action:'regenerate'} → issues a new random token
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email'] ?? ''));

$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com';
$feedBase = $proto . '://' . $host . '/api/cal_feed.php?token=';

function upsert_cal_token(PDO $db, string $email, string $tok): void {
    $db->prepare("INSERT INTO agent_extra (email, cal_token, updated_at)
                  VALUES (?, ?, datetime('now'))
                  ON CONFLICT(email) DO UPDATE SET
                    cal_token=excluded.cal_token,
                    updated_at=excluded.updated_at")
       ->execute([$email, $tok]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($body['action'] ?? '') === 'regenerate') {
        $tok = bin2hex(random_bytes(24));
        upsert_cal_token($db, $email, $tok);
        echo json_encode(['ok' => true, 'token' => $tok, 'feed_url' => $feedBase . urlencode($tok)]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

// GET — return existing token, create if missing
$row = $db->prepare("SELECT cal_token FROM agent_extra WHERE email=?");
$row->execute([$email]);
$r   = $row->fetch(PDO::FETCH_ASSOC);
$tok = $r['cal_token'] ?? '';
if ($tok === '') {
    $tok = bin2hex(random_bytes(24));
    upsert_cal_token($db, $email, $tok);
}
echo json_encode(['ok' => true, 'token' => $tok, 'feed_url' => $feedBase . urlencode($tok)]);
