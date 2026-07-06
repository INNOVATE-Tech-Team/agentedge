<?php
// Agent intake form API — native replacement for the Google Form.
// GET (no action)           → load own (or admin: any agent's) intake data + headshot list
// GET action=list           → admin: all agents with intake status
// GET action=headshot&key=  → serve a headshot image file
// POST action=save (default)→ upsert intake data
// POST action=upload        → upload a headshot (multipart/form-data, field: headshot)
// POST action=delete_file   → delete a headshot by key
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/crypto.php';

function intake_json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$pdo     = local_db();
$myEmail = strtolower(trim($agent['email'] ?? ''));
$isAdmin = is_admin();
$action  = $_GET['action'] ?? '';

// ── GET: serve headshot file ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'headshot') {
    $key = trim($_GET['key'] ?? '');
    if (!$key || !preg_match('/^[a-f0-9]+\.[a-z]{2,5}$/i', $key)) {
        header('Content-Type: application/json');
        intake_json_out(['error' => 'invalid key'], 400);
    }
    $st = $pdo->prepare("SELECT agent_email, orig_name, mime_type FROM agent_intake_files WHERE file_key=?");
    $st->execute([$key]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) { header('Content-Type: application/json'); intake_json_out(['error' => 'not found'], 404); }
    if (!$isAdmin && strtolower($file['agent_email']) !== $myEmail) {
        header('Content-Type: application/json'); intake_json_out(['error' => 'forbidden'], 403);
    }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $path    = $dataDir . '/headshots/' . basename($key);
    if (!file_exists($path)) { header('Content-Type: application/json'); intake_json_out(['error' => 'file not found'], 404); }

    header('Content-Type: ' . ($file['mime_type'] ?: 'image/jpeg'));
    header('Content-Disposition: inline; filename="' . addslashes(basename($file['orig_name'])) . '"');
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── GET: list all agents with intake status (admin only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    header('Content-Type: application/json');
    if (!$isAdmin) intake_json_out(['error' => 'admin only'], 403);
    $rows = $pdo->query(
        "SELECT i.email, i.full_name, i.phone, i.office_location, i.bio,
                i.submitted, i.submitted_at, i.updated_at, ar.role
         FROM agent_intake i
         LEFT JOIN agent_roles ar ON ar.email = i.email
         ORDER BY i.submitted DESC, i.updated_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    intake_json_out(['ok' => true, 'agents' => $rows]);
}

// ── GET: load a single agent's intake data ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $email = $myEmail;
    if (!empty($_GET['email'])) {
        $requested = strtolower(trim($_GET['email']));
        if (!$isAdmin && $requested !== $myEmail) intake_json_out(['error' => 'forbidden'], 403);
        $email = $requested;
    }
    $st = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // Never send the encrypted (or decrypted) tax ID back to the browser —
    // only a last-4 hint so the form can show "on file" without exposing it.
    if (array_key_exists('personal_tax_id_enc', $row)) {
        $row['personal_tax_id_last4'] = tax_id_last4($row['personal_tax_id_enc']);
        unset($row['personal_tax_id_enc']);
    }
    if (array_key_exists('corporate_tax_id_enc', $row)) {
        $row['corporate_tax_id_last4'] = tax_id_last4($row['corporate_tax_id_enc']);
        unset($row['corporate_tax_id_enc']);
    }

    $fst = $pdo->prepare(
        "SELECT file_key, orig_name, size_bytes FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at"
    );
    $fst->execute([$email]);
    $headshots = $fst->fetchAll(PDO::FETCH_ASSOC);

    intake_json_out(['ok' => true, 'intake' => $row, 'headshots' => $headshots]);
}

// ── All remaining actions require POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    intake_json_out(['error' => 'POST required'], 405);
}
header('Content-Type: application/json');
$postAction = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── POST: upload headshot ─────────────────────────────────────────────────────
if ($postAction === 'upload') {
    if (empty($_FILES['headshot']) || $_FILES['headshot']['error'] !== UPLOAD_ERR_OK) {
        intake_json_out(['ok' => false, 'error' => 'No valid file received'], 400);
    }
    $f = $_FILES['headshot'];
    if ($f['size'] > 10 * 1024 * 1024) {
        intake_json_out(['ok' => false, 'error' => 'File exceeds 10 MB limit'], 400);
    }
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        intake_json_out(['ok' => false, 'error' => 'Only JPEG, PNG, GIF, or WebP images are allowed'], 400);
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM agent_intake_files WHERE agent_email=?");
    $cnt->execute([$myEmail]);
    if ((int)$cnt->fetchColumn() >= 5) {
        intake_json_out(['ok' => false, 'error' => 'Maximum 5 headshots allowed per agent'], 400);
    }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $hsDir   = $dataDir . '/headshots';
    if (!is_dir($hsDir)) @mkdir($hsDir, 0750, true);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $key = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $hsDir . '/' . $key)) {
        intake_json_out(['ok' => false, 'error' => 'Could not save uploaded file'], 500);
    }
    $pdo->prepare(
        "INSERT INTO agent_intake_files (agent_email, file_key, orig_name, mime_type, size_bytes)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$myEmail, $key, basename($f['name']), $mime, $f['size']]);

    intake_json_out(['ok' => true, 'file_key' => $key, 'orig_name' => basename($f['name']), 'size_bytes' => $f['size']]);
}

// ── POST: delete headshot ─────────────────────────────────────────────────────
if ($postAction === 'delete_file') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $key  = trim($body['key'] ?? '');
    if (!$key) intake_json_out(['ok' => false, 'error' => 'key required'], 400);

    $st = $pdo->prepare("SELECT agent_email FROM agent_intake_files WHERE file_key=?");
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) intake_json_out(['ok' => false, 'error' => 'File not found'], 404);
    if (strtolower($row['agent_email']) !== $myEmail && !$isAdmin) {
        intake_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    @unlink($dataDir . '/headshots/' . basename($key));
    $pdo->prepare("DELETE FROM agent_intake_files WHERE file_key=?")->execute([$key]);
    intake_json_out(['ok' => true]);
}

// ── POST: save intake data (default) ─────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$body) { foreach ($_POST as $k => $v) if ($k !== 'action') $body[$k] = $v; }

$email = $myEmail;
if ($isAdmin && !empty($body['email'])) $email = strtolower(trim($body['email']));

$fv = fn($k) => trim($body[$k] ?? '');

$fields = [
    'full_name', 'phone', 'license_number', 'license_state', 'license_exp',
    'nar_number', 'mls_board', 'mls_id', 'office_location', 'birthday',
    'mailing_address', 'spouse_name', 'emergency_name', 'emergency_phone', 'bio',
    'tshirt_size', 'is_military', 'first_responder', 'is_teacher',
    'phone_last4', 'referring_agent', 'languages',
    'personal_email', 'commissions_email',
    'address_line1', 'address_line2', 'city', 'state', 'zip', 'country',
    'drivers_license', 'gender',
    'website', 'additional_websites', 'facebook', 'linkedin', 'skype', 'email_signature',
    'corporation_start', 'corporation_end', 'career_start',
    'prior_occupation', 'prior_affiliation', 'specialty',
    'full_time', 'show_on_internet',
    'personal_tax_id_enc', 'corporate_tax_id_enc',
];

// The GET above never sends the real tax ID back to the browser (only a
// last-4 hint), so a re-save with the field left blank must NOT clobber the
// already-stored encrypted value — only overwrite it when a new value is typed.
$preserveIfBlank = ['personal_tax_id_enc', 'corporate_tax_id_enc'];

$resolveField = function (string $f) use ($fv, $body): string {
    switch ($f) {
        case 'full_time':
        case 'show_on_internet':
            return isset($body[$f]) ? ($body[$f] ? '1' : '0') : '1';
        case 'personal_tax_id_enc':
            return tax_id_encrypt($fv('personal_tax_id'));
        case 'corporate_tax_id_enc':
            return tax_id_encrypt($fv('corporate_tax_id'));
        default:
            return $fv($f);
    }
};

$required = [
    'full_name', 'phone', 'license_number', 'nar_number', 'mls_board',
    'office_location', 'birthday', 'address_line1', 'city', 'state', 'zip',
    'emergency_name', 'emergency_phone', 'bio',
];
$complete = true;
foreach ($required as $r) if ($fv($r) === '') { $complete = false; break; }

$prev = $pdo->prepare("SELECT submitted FROM agent_intake WHERE email=?");
$prev->execute([$email]);
$pr          = $prev->fetch(PDO::FETCH_ASSOC);
$wasSubmitted = !empty($pr['submitted']);
$isSubmitted  = $complete || $wasSubmitted;

$now  = date('Y-m-d H:i:s');
$cols = implode(',', $fields);
$phs  = implode(',', array_fill(0, count($fields), '?'));
$upds = implode(',', array_map(function (string $f) use ($preserveIfBlank): string {
    if (in_array($f, $preserveIfBlank, true)) {
        return "$f = CASE WHEN excluded.$f <> '' THEN excluded.$f ELSE agent_intake.$f END";
    }
    return "$f=excluded.$f";
}, $fields));

$pdo->prepare(
    "INSERT INTO agent_intake (email,$cols,submitted,submitted_at,updated_at)
     VALUES (?,$phs,?,?,?)
     ON CONFLICT(email) DO UPDATE SET
         $upds,
         submitted    = MAX(agent_intake.submitted, excluded.submitted),
         submitted_at = COALESCE(agent_intake.submitted_at,
                            CASE WHEN excluded.submitted=1 THEN excluded.submitted_at ELSE NULL END),
         updated_at   = excluded.updated_at"
)->execute(array_merge(
    [$email],
    array_map($resolveField, $fields),
    [$isSubmitted ? 1 : 0, ($isSubmitted && !$wasSubmitted) ? $now : null, $now]
));

intake_json_out(['ok' => true, 'submitted' => $isSubmitted]);
