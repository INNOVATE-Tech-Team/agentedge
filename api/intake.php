<?php
// Agent intake form API — native replacement for the Google Form.
// GET (no action)           → load own (or admin: any agent's) intake data + headshot list
// GET action=list           → admin: all agents with intake status
// GET action=headshot&key=  → serve a headshot image file (a small thumbnail; &full=1 for the
//                             original inline, as the headshot cropper needs; &dl=1 to download it)
// POST action=save (default)→ upsert intake data
// POST action=upload        → upload a headshot (multipart/form-data, field: headshot; optional field email, admin only)
// POST action=delete_file   → delete a headshot by key
// POST action=set_headshot  → choose one uploaded photo as the headshot, with its square crop
//                             (multipart: key, crop file, rect JSON; optional email, admin only)
// GET  action=headshot_crop&email= → serve the agent's chosen square headshot crop
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/crypto.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/headshot_select.php';

function intake_json_out(array $d, int $code = 200, bool $dispatch = false): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    // $dispatch drains notification_queue in-request (right after a successful
    // submit) instead of leaving staff alerts to wait on the next cron cycle —
    // wrapped so a delivery hiccup here can never turn an already-succeeded
    // save into an error response.
    if ($dispatch) {
        try { dispatch_notification_queue(); } catch (\Throwable $e) {}
    }
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
    if (!is_leader() && strtolower($file['agent_email']) !== $myEmail) {
        header('Content-Type: application/json'); intake_json_out(['error' => 'forbidden'], 403);
    }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $path    = $dataDir . '/headshots/' . basename($key);
    if (!file_exists($path)) { header('Content-Type: application/json'); intake_json_out(['error' => 'file not found'], 404); }

    $isDl = !empty($_GET['dl']);
    $disposition = $isDl ? 'attachment' : 'inline';

    // For inline display serve a small JPEG thumbnail (≤128px, ~5-20 KB)
    // instead of the raw upload (often 1-2 MB PNG). Falls back to the
    // original if thumbnail generation fails (e.g. unsupported format).
    // &full=1 skips the thumbnail: the headshot cropper frames the original.
    if (!$isDl && empty($_GET['full'])) {
        require_once __DIR__ . '/../lib/headshot_thumb.php';
        $thumbPath = ensure_headshot_thumbnail($path, $key);
        if ($thumbPath) {
            header('Content-Type: image/jpeg');
            header('Content-Disposition: inline; filename="' . addslashes(pathinfo($file['orig_name'], PATHINFO_FILENAME)) . '.jpg"');
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($thumbPath));
            readfile($thumbPath);
            exit;
        }
    }

    // Download, or thumbnail generation failed — serve the original.
    header('Content-Type: ' . ($file['mime_type'] ?: 'image/jpeg'));
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes(basename($file['orig_name'])) . '"');
    header('Cache-Control: ' . ($isDl ? 'private' : 'public') . ', max-age=86400');
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

// ── GET: serve the chosen square headshot crop ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'headshot_crop') {
    $email = strtolower(trim($_GET['email'] ?? '')) ?: $myEmail;
    if (!is_leader() && $email !== $myEmail) {
        header('Content-Type: application/json'); intake_json_out(['error' => 'forbidden'], 403);
    }
    $sel  = headshot_select_get($pdo, $email);
    $path = $sel ? headshot_crop_dir() . '/' . basename($sel['crop_key']) : '';
    if (!$sel || !is_file($path)) { header('Content-Type: application/json'); intake_json_out(['error' => 'no headshot chosen'], 404); }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
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
    $hsDataDir = (function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null) ?: (__DIR__ . '/../data');
    foreach ($headshots as &$hs) {
        $dims = @getimagesize($hsDataDir . '/headshots/' . basename($hs['file_key']));
        $hs['width']  = $dims ? $dims[0] : null;
        $hs['height'] = $dims ? $dims[1] : null;
    }
    unset($hs);

    $lst = $pdo->prepare(
        "SELECT license_number, license_state, license_exp FROM agent_intake_licenses WHERE agent_email=? ORDER BY id"
    );
    $lst->execute([$email]);
    $additionalLicenses = $lst->fetchAll(PDO::FETCH_ASSOC);

    $mst = $pdo->prepare(
        "SELECT mls_association, mls_number FROM agent_mls_memberships WHERE agent_email=? ORDER BY id"
    );
    $mst->execute([$email]);
    $mlsMemberships = $mst->fetchAll(PDO::FETCH_ASSOC);

    $selected = headshot_select_get($pdo, $email);

    intake_json_out(['ok' => true, 'intake' => $row, 'headshots' => $headshots, 'headshot' => $selected, 'additional_licenses' => $additionalLicenses, 'mls_memberships' => $mlsMemberships]);
}

// ── All remaining actions require POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    intake_json_out(['error' => 'POST required'], 405);
}
header('Content-Type: application/json');
$postAction = $_GET['action'] ?? ($_POST['action'] ?? '');

// Minimum shortest side for an uploaded photo, in pixels. The website shows
// headshots up to ~480px wide, so anything smaller looks blurry; the photo
// manager (assets/headshot-manager.js) checks the same floor before upload.
const HEADSHOT_MIN_SIDE  = 400;
// Uploaded originals are kept for marketing use, capped at this size. The
// photo manager already scales and re-encodes in the browser (which also
// applies phone rotation correctly); this only catches raw uploads.
const HEADSHOT_ORIG_MAX  = 3000;
// The chosen headshot's square crop.
const HEADSHOT_CROP_MAX  = 800;

function headshot_gd_open(string $path, string $mime) {
    $create = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif'  => 'imagecreatefromgif',
    ][$mime] ?? null;
    if (!$create || !function_exists($create)) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    // Full-size phone originals can need more memory to decode than PHP
    // allows, and running out is a fatal error, not a catchable one.
    if ($info[0] * $info[1] * 5 > 120 * 1024 * 1024) return null;
    @ini_set('memory_limit', '256M');
    return @$create($path) ?: null;
}

// Scales an uploaded original down to HEADSHOT_ORIG_MAX if it's larger,
// keeping its format (PNG transparency included). Smaller files are left
// byte-for-byte untouched.
function limit_original_in_place(string $path, string $mime): void {
    $info = @getimagesize($path);
    if (!$info || max($info[0], $info[1]) <= HEADSHOT_ORIG_MAX || $mime === 'image/gif') return;
    $src = headshot_gd_open($path, $mime);
    if (!$src) return;
    $w = imagesx($src); $h = imagesy($src);
    $scale = HEADSHOT_ORIG_MAX / max($w, $h);
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    switch ($mime) {
        case 'image/jpeg': imagejpeg($dst, $path, 90); break;
        case 'image/png':  imagepng($dst, $path, 6); break;
        case 'image/webp': imagewebp($dst, $path, 90); break;
    }
    imagedestroy($src); imagedestroy($dst);
}

// Re-encodes a headshot crop as a square JPEG of at most HEADSHOT_CROP_MAX,
// center-cropping if it somehow isn't square. Never upscales.
function square_headshot_in_place(string $path, string $mime): bool {
    $src = headshot_gd_open($path, $mime);
    if (!$src) return false;
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    $out  = min($side, HEADSHOT_CROP_MAX);
    $dst  = imagecreatetruecolor($out, $out);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $out, $out, $side, $side);
    $ok = imagejpeg($dst, $path, 88);
    imagedestroy($src); imagedestroy($dst);
    return $ok;
}

// Admins may act on another agent's photos via an `email` field.
function headshot_target_email(string $myEmail, bool $isAdmin): string {
    if (empty($_POST['email'])) return $myEmail;
    $requested = strtolower(trim($_POST['email']));
    if (!$isAdmin && $requested !== $myEmail) intake_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    return $requested;
}

// ── POST: upload headshot ─────────────────────────────────────────────────────
if ($postAction === 'upload') {
    $targetEmail = headshot_target_email($myEmail, $isAdmin);
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
    $dims = @getimagesize($f['tmp_name']);
    if (!$dims) {
        intake_json_out(['ok' => false, 'error' => 'Could not read that image. Please choose a JPEG or PNG photo.'], 400);
    }
    if (min($dims[0], $dims[1]) < HEADSHOT_MIN_SIDE) {
        intake_json_out(['ok' => false, 'error' => 'This photo is only ' . $dims[0] . '×' . $dims[1]
            . ' pixels. Please choose a photo at least ' . HEADSHOT_MIN_SIDE . '×' . HEADSHOT_MIN_SIDE . '.'], 400);
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM agent_intake_files WHERE agent_email=?");
    $cnt->execute([$targetEmail]);
    if ((int)$cnt->fetchColumn() >= 10) {
        intake_json_out(['ok' => false, 'error' => 'Maximum 10 photos allowed per agent. Delete one to upload another.'], 400);
    }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $hsDir   = $dataDir . '/headshots';
    if (!is_dir($hsDir)) @mkdir($hsDir, 0750, true);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $key = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $hsDir . '/' . $key;
    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
        intake_json_out(['ok' => false, 'error' => 'Could not save uploaded file'], 500);
    }
    // Kept uncropped: staff use the originals for marketing. Only the photo
    // chosen as the headshot gets a square crop (set_headshot below).
    limit_original_in_place($destPath, $mime);
    clearstatcache(true, $destPath);
    $sizeBytes = @filesize($destPath) ?: $f['size'];
    $pdo->prepare(
        "INSERT INTO agent_intake_files (agent_email, file_key, orig_name, mime_type, size_bytes)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$targetEmail, $key, basename($f['name']), $mime, $sizeBytes]);

    intake_json_out(['ok' => true, 'file_key' => $key, 'orig_name' => basename($f['name']), 'size_bytes' => $sizeBytes]);
}

// ── POST: choose the headshot ─────────────────────────────────────────────────
// The browser sends the square crop it made from one of the agent's
// uploaded photos, plus the frame it used (so "Adjust crop" can reopen it).
if ($postAction === 'set_headshot') {
    $targetEmail = headshot_target_email($myEmail, $isAdmin);
    $sourceKey   = trim($_POST['key'] ?? '');
    $own = $pdo->prepare("SELECT 1 FROM agent_intake_files WHERE file_key=? AND agent_email=?");
    $own->execute([$sourceKey, $targetEmail]);
    if (!$sourceKey || !$own->fetchColumn()) intake_json_out(['ok' => false, 'error' => 'Photo not found'], 404);

    if (empty($_FILES['crop']) || $_FILES['crop']['error'] !== UPLOAD_ERR_OK) {
        intake_json_out(['ok' => false, 'error' => 'No cropped image received'], 400);
    }
    $f    = $_FILES['crop'];
    $mime = mime_content_type($f['tmp_name']);
    $dims = @getimagesize($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$dims) {
        intake_json_out(['ok' => false, 'error' => 'Could not read the cropped image'], 400);
    }
    // The cropper never frames less than HEADSHOT_MIN_SIDE source pixels;
    // allow a little rounding slack.
    if (min($dims[0], $dims[1]) < HEADSHOT_MIN_SIDE - 10) {
        intake_json_out(['ok' => false, 'error' => 'The cropped headshot is too small. Please zoom out a little.'], 400);
    }

    $rect = json_decode($_POST['rect'] ?? '', true);
    $rect = is_array($rect) && isset($rect['x'], $rect['y'], $rect['size'])
        ? ['x' => (int)$rect['x'], 'y' => (int)$rect['y'], 'size' => (int)$rect['size']]
        : null;

    $dir = headshot_crop_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $cropKey  = bin2hex(random_bytes(16)) . '.jpg';
    $cropPath = $dir . '/' . $cropKey;
    if (!move_uploaded_file($f['tmp_name'], $cropPath)) {
        intake_json_out(['ok' => false, 'error' => 'Could not save the headshot'], 500);
    }
    if (!square_headshot_in_place($cropPath, $mime)) {
        @unlink($cropPath);
        intake_json_out(['ok' => false, 'error' => 'Could not process the headshot'], 500);
    }
    headshot_select_save($pdo, $targetEmail, $sourceKey, $cropKey, $rect);
    intake_json_out(['ok' => true, 'headshot' => headshot_select_get($pdo, $targetEmail)]);
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
    headshot_select_forget_source($pdo, strtolower($row['agent_email']), $key);
    intake_json_out(['ok' => true]);
}

// ── POST: save intake data (default) ─────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$body) { foreach ($_POST as $k => $v) if ($k !== 'action') $body[$k] = $v; }

$email = $myEmail;
if ($isAdmin && !empty($body['email'])) $email = strtolower(trim($body['email']));

// An agent can now hold multiple MLS memberships (see agent_mls_memberships
// below), but agent_intake.mls_board/mls_id remain single-value columns that
// other code still reads directly (list views, roster export, etc). Mirror
// the first membership into them here, before the generic $fields save below
// runs off $body, so that save (and the "is this profile complete" required-
// field check) keeps working against whichever membership the agent listed
// first -- without every other reader needing to learn about the new table.
if (is_array($body['mls_memberships'] ?? null)) {
    // Mirror the first membership that actually names an association -- not
    // just array index 0 -- so a row with only an ID number sitting before it
    // in list order doesn't blank out mls_board (see api/intake_public.php).
    $primaryMembership = null;
    foreach ($body['mls_memberships'] as $membership) {
        if (trim($membership['mls_association'] ?? '') !== '') { $primaryMembership = $membership; break; }
    }
    $primaryMembership = $primaryMembership ?? ($body['mls_memberships'][0] ?? []);
    $body['mls_board'] = trim($primaryMembership['mls_association'] ?? '');
    $body['mls_id']    = trim($primaryMembership['mls_number'] ?? '');
}

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
    'website', 'additional_websites', 'facebook', 'linkedin', 'skype', 'instagram',
    'twitter', 'youtube', 'tiktok', 'blog',
    'corporation_start', 'corporation_end', 'career_start',
    'prior_occupation', 'prior_affiliation', 'specialty',
    'full_time', 'show_on_internet',
    'personal_tax_id_enc', 'corporate_tax_id_enc',
];

// The GET above never sends the real tax ID back to the browser (only a
// last-4 hint), so a re-save with the field left blank must NOT clobber the
// already-stored encrypted value — only overwrite it when a new value is typed.
$preserveIfBlank = ['personal_tax_id_enc', 'corporate_tax_id_enc'];

// Fetched here (before $resolveField needs it) rather than at its original
// spot further down, so a field this specific caller's form doesn't even
// include can fall back to what's already stored instead of being silently
// blanked. Real incident, 2026-09-02: agent_profile.php's Edit Profile modal
// only ever sent a subset of $fields (missing mailing_address/instagram/
// twitter/youtube/tiktok/blog, among others) — every save through it wiped
// whichever of those columns already had data, since the old default case
// below just did $fv($f), which is '' for any key never sent at all. A
// caller that DOES send a field (even as an intentionally-cleared empty
// string, e.g. the self-service Intake Form which sends the whole form
// every time) still gets that clear applied — this only protects columns
// the request never mentions.
$prev = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?");
$prev->execute([$email]);
$pr = $prev->fetch(PDO::FETCH_ASSOC) ?: [];

$resolveField = function (string $f) use ($fv, $body, $pr): string {
    switch ($f) {
        case 'full_time':
        case 'show_on_internet':
            return isset($body[$f]) ? ($body[$f] ? '1' : '0') : '1';
        case 'personal_tax_id_enc':
            return tax_id_encrypt($fv('personal_tax_id'));
        case 'corporate_tax_id_enc':
            return tax_id_encrypt($fv('corporate_tax_id'));
        default:
            return array_key_exists($f, $body) ? $fv($f) : (string)($pr[$f] ?? '');
    }
};

$required = [
    'full_name', 'phone', 'license_number', 'nar_number',
    'office_location', 'birthday', 'address_line1', 'city', 'state', 'zip',
    'emergency_name', 'emergency_phone', 'bio', 'referring_agent',
];
$complete = true;
foreach ($required as $r) if ($fv($r) === '') { $complete = false; break; }

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

$newValues = array_map($resolveField, $fields);

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
    $newValues,
    [$isSubmitted ? 1 : 0, ($isSubmitted && !$wasSubmitted) ? $now : null, $now]
));

// Many older/manually-added roster rows have no email on file (the whole
// reason createMissingProfile()'s CRM-lookup/manual-prompt flow in
// backoffice_agents.php exists) — once a profile save resolves a real email,
// backfill it into innovate_roster too. Without this, agent_intake and
// innovate_roster silently diverge: Teams/team_members, Darwin email-first
// production matching, and anything else keyed on innovate_roster.email
// never learn the email this profile has on file. Only ever fills a blank,
// matched by name since that's the only link available when the roster row
// has no email of its own (same fallback backoffice_agents.php's own
// $byRosterName lookup already relies on).
$fullName = $fv('full_name');
if ($email !== '' && $fullName !== '') {
    $pdo->prepare(
        "UPDATE innovate_roster SET email=? WHERE active=1 AND (email='' OR email IS NULL) AND lower(agent_name)=lower(?)"
    )->execute([$email, $fullName]);
}

// ── Additional licenses (rewritten in full on every save) ─────────────────────
$pdo->prepare("DELETE FROM agent_intake_licenses WHERE agent_email=?")->execute([$email]);
$additionalLicenses = is_array($body['additional_licenses'] ?? null) ? $body['additional_licenses'] : [];
$insLicense = $pdo->prepare(
    "INSERT INTO agent_intake_licenses (agent_email, license_number, license_state, license_exp) VALUES (?,?,?,?)"
);
foreach ($additionalLicenses as $lic) {
    $num   = trim($lic['license_number'] ?? '');
    $state = trim($lic['license_state'] ?? '');
    $exp   = trim($lic['license_exp'] ?? '');
    if ($num === '' && $state === '' && $exp === '') continue;
    $insLicense->execute([$email, $num, $state, $exp]);
}

// ── MLS memberships (rewritten in full on every save) ─────────────────────────
$pdo->prepare("DELETE FROM agent_mls_memberships WHERE agent_email=?")->execute([$email]);
$mlsMemberships = is_array($body['mls_memberships'] ?? null) ? $body['mls_memberships'] : [];
$insMembership = $pdo->prepare(
    "INSERT INTO agent_mls_memberships (agent_email, mls_association, mls_number) VALUES (?,?,?)"
);
foreach ($mlsMemberships as $mem) {
    $assoc  = trim($mem['mls_association'] ?? '');
    $number = trim($mem['mls_number'] ?? '');
    if ($assoc === '' && $number === '') continue;
    $insMembership->execute([$email, $assoc, $number]);
}

// Heads-up email to Whitney when the AGENT submits their own Intake Form —
// never for a staff/admin edit made on someone else's behalf (back-office
// Edit Profile modal, where $email !== $myEmail).
if ($email === $myEmail) {
    $boolFields  = ['full_time', 'show_on_internet'];
    $boolLabel   = fn(string $v): string => ($v === '' ? '1' : $v) === '1' ? 'Yes' : 'No';
    $changes     = [];

    foreach ($fields as $i => $f) {
        if (in_array($f, $preserveIfBlank, true)) {
            $rawKey = $f === 'personal_tax_id_enc' ? 'personal_tax_id' : 'corporate_tax_id';
            if ($fv($rawKey) !== '') {
                $label = $f === 'personal_tax_id_enc' ? 'Personal Tax ID' : 'Corporate Tax ID (EIN)';
                $changes[$label] = ['(on file)', '(updated)'];
            }
            continue;
        }
        $newVal = $newValues[$i];
        $oldVal = (string)($pr[$f] ?? '');
        if (in_array($f, $boolFields, true)) { $newVal = $boolLabel($newVal); $oldVal = $boolLabel($oldVal); }
        if ($newVal !== $oldVal) {
            $changes[ucwords(str_replace('_', ' ', $f))] = [$oldVal, $newVal];
        }
    }

    notify_profile_changed($fv('full_name') ?: $myEmail, $myEmail, $changes);

    if ($isSubmitted && !$wasSubmitted) {
        notify_intake_submitted($fv('full_name') ?: $myEmail, $myEmail);
        notify_upline_intake_submitted($fv('full_name') ?: $myEmail, $myEmail, $fv('office_location'));
        notify_intake_summary_admins($myEmail);
        notify_bic_ml_intake_submitted($fv('full_name') ?: $myEmail, $myEmail, $fv('office_location'));
    }
}

// Mirrors the submitted_at COALESCE logic in the UPSERT above: unchanged if
// this was already submitted before, freshly stamped to $now on the save
// that flips it to submitted, null while still a draft.
$submittedAt = $isSubmitted ? ($wasSubmitted ? ($pr['submitted_at'] ?? null) : $now) : null;

intake_json_out(['ok' => true, 'submitted' => $isSubmitted, 'submitted_at' => $submittedAt], 200, true);
