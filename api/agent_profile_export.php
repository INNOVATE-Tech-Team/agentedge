<?php
// Agent profile export — bio, headshot, phone, license, and specialty, for
// coastline-server's nightly agent-sites sync (activates
// website.innovateonline.com/<slug> pages). Token-gated the same way
// api/roster_export.php and api/onboard_push.php are (crm_token) — no login
// session involved, this is server-to-server.
//
// GET /api/agent_profile_export.php?token=...
//   Bulk metadata only (no image bytes) — cheap to call for the whole roster.
//   Response: { profiles: [{ email, bio, phone, license_number, license_state,
//              specialty, headshot_uploaded_at }] }
//
// GET /api/agent_profile_export.php?action=headshot&email=...&token=...
//   Streams the agent's most recently uploaded headshot image.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

$c     = cfg();
$token = $c['crm_token'] ?? '';
$given = trim($_GET['token'] ?? $_SERVER['HTTP_X_AGENTEDGE_TOKEN'] ?? '');

if ($token === '' || $given === '') {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'crm_token not configured or missing']);
    exit;
}
if (!hash_equals($token, $given)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

$pdo    = local_db();
$action = $_GET['action'] ?? '';

// ── Serve one agent's latest headshot ──────────────────────────────────
if ($action === 'headshot') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if ($email === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'email required']);
        exit;
    }
    $st = $pdo->prepare(
        "SELECT file_key, orig_name, mime_type FROM agent_intake_files
         WHERE agent_email = ? ORDER BY uploaded_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'no headshot']);
        exit;
    }
    $cfgDir  = $c['local_db_dir'] ?? null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $path    = $dataDir . '/headshots/' . basename($file['file_key']);
    if (!file_exists($path)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'file missing on disk']);
        exit;
    }
    header('Content-Type: ' . ($file['mime_type'] ?: 'image/jpeg'));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Bulk profile + headshot-freshness metadata ──────────────────────────
// Broadened from bio/headshot-only so the website can carry phone/license/
// specialty too, not just bio+photo — every field intake.php collects that
// the site has a column for. Filter widened to match: anyone with ANY of
// these fields set, not just bio or a headshot.
header('Content-Type: application/json');
$rows = $pdo->query(
    "SELECT i.email, i.bio, i.phone, i.license_number, i.license_state, i.specialty,
            (SELECT MAX(f.uploaded_at) FROM agent_intake_files f WHERE f.agent_email = i.email) AS headshot_uploaded_at
     FROM agent_intake i
     WHERE i.bio IS NOT NULL AND i.bio != ''
        OR i.phone IS NOT NULL AND i.phone != ''
        OR i.license_number IS NOT NULL AND i.license_number != ''
        OR i.specialty IS NOT NULL AND i.specialty != ''
        OR EXISTS (SELECT 1 FROM agent_intake_files f WHERE f.agent_email = i.email)"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['profiles' => $rows]);
