<?php
// POST handler: validate + store files, upload to DotLoop, email Michele, log.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/dotloop.php';

header('Content-Type: application/json');

function json_err(string $msg): void {
    echo json_encode(['ok' => false, 'error' => $msg]); exit;
}

$agent = current_agent();
if (!$agent) json_err('Not logged in');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required');

$loopId   = trim($_POST['loop_id']   ?? '');
$loopName = trim($_POST['loop_name'] ?? '');
if ($loopId === '')   json_err('No transaction selected');
if ($loopName === '') $loopName = 'Unknown transaction';

// ── Validate uploaded files ───────────────────────────────────────────────────
$allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxBytes = 20 * 1024 * 1024; // 20 MB

function validate_upload(string $key): array {
    global $allowed, $maxBytes;
    if (empty($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE;
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
        ];
        return ['ok' => false, 'error' => $msgs[$code] ?? 'Upload error'];
    }
    if ($_FILES[$key]['size'] > $maxBytes) return ['ok' => false, 'error' => 'File exceeds 20 MB limit'];
    $mime = mime_content_type($_FILES[$key]['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Only PDF and image files are accepted'];
    }
    return ['ok' => true, 'tmp' => $_FILES[$key]['tmp_name'], 'name' => $_FILES[$key]['name'], 'mime' => $mime];
}

$hudFile   = validate_upload('hud_file');
$checkFile = validate_upload('check_file');

if (!$hudFile['ok'])   json_err('HUD file: ' . $hudFile['error']);
if (!$checkFile['ok']) json_err('Check file: ' . $checkFile['error']);

// ── Store files permanently ───────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../data/hud_uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0750, true);

$prefix  = date('Ymd_His') . '_' . substr(md5($agent['email']), 0, 6);
$hudExt   = pathinfo($hudFile['name'],   PATHINFO_EXTENSION);
$checkExt = pathinfo($checkFile['name'], PATHINFO_EXTENSION);

$hudStored   = $prefix . '_hud.'   . ($hudExt   ?: 'pdf');
$checkStored = $prefix . '_check.' . ($checkExt ?: 'pdf');

if (!move_uploaded_file($hudFile['tmp'],   $uploadDir . '/' . $hudStored))   json_err('Could not save HUD file');
if (!move_uploaded_file($checkFile['tmp'], $uploadDir . '/' . $checkStored)) json_err('Could not save Check file');

// ── Upload to DotLoop ─────────────────────────────────────────────────────────
$email     = $agent['email'];
$connected = dotloop_is_connected($email);
$tokens    = $connected ? dotloop_get_tokens($email) : null;
$profileId = $tokens['profile_id'] ?? '';

$dlNotes      = [];
$dlOk         = false;
$dlHudDocId   = null;
$dlCheckDocId = null;
$dlFolderId   = null;

if ($connected && $profileId !== '') {
    $fResult = dotloop_get_folders($email, $profileId, $loopId);
    if ($fResult['ok'] && !empty($fResult['data'])) {
        $folders    = $fResult['data'];
        $hudFolder  = dotloop_pick_folder($folders, 'hud');
        $checkFolder = dotloop_pick_folder($folders, 'check');

        $hudUp = dotloop_upload_document(
            $email, $profileId, $loopId,
            $hudFolder,
            $uploadDir . '/' . $hudStored,
            $hudFile['name'],
            $hudFile['mime']
        );
        if ($hudUp['ok']) {
            $dlHudDocId = $hudUp['data']['data']['id'] ?? null;
            $dlFolderId = $hudFolder;
        } else {
            $dlNotes[] = 'HUD upload to DotLoop failed: ' . $hudUp['error'];
        }

        $checkUp = dotloop_upload_document(
            $email, $profileId, $loopId,
            $checkFolder,
            $uploadDir . '/' . $checkStored,
            $checkFile['name'],
            $checkFile['mime']
        );
        if ($checkUp['ok']) {
            $dlCheckDocId = $checkUp['data']['data']['id'] ?? null;
        } else {
            $dlNotes[] = 'Check upload to DotLoop failed: ' . $checkUp['error'];
        }

        $dlOk = $hudUp['ok'] && $checkUp['ok'];
    } else {
        $dlNotes[] = 'Could not retrieve loop folders from DotLoop';
    }
} else {
    $dlNotes[] = 'Agent not connected to DotLoop — files saved locally only';
}

// ── Log submission ────────────────────────────────────────────────────────────
local_db()->prepare(
    "INSERT INTO hud_submissions
        (agent_email, agent_name, loop_id, loop_name,
         hud_original, check_original, hud_stored, check_stored,
         dl_hud_doc_id, dl_check_doc_id, dl_folder_id,
         dotloop_ok, email_sent, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)"
)->execute([
    $agent['email'],
    $agent['name'] ?? $agent['email'],
    $loopId,
    $loopName,
    $hudFile['name'],
    $checkFile['name'],
    $hudStored,
    $checkStored,
    $dlHudDocId,
    $dlCheckDocId,
    $dlFolderId,
    $dlOk ? 1 : 0,
    implode('; ', $dlNotes) ?: null,
]);
$submissionId = local_db()->lastInsertId();

// ── Email Michele ─────────────────────────────────────────────────────────────
$agentName = $agent['name'] ?? $agent['email'];
$dlStatus  = $dlOk
    ? '✅ Successfully uploaded to DotLoop'
    : '⚠️ Could not upload to DotLoop — files saved locally. ' . implode(' ', $dlNotes);

$subject = "HUD & Check Submitted — {$agentName} — {$loopName}";
$body    = "<h2>HUD &amp; Check Document Submission</h2>"
         . "<p><strong>Agent:</strong> " . htmlspecialchars($agentName) . " (" . htmlspecialchars($agent['email']) . ")</p>"
         . "<p><strong>Transaction:</strong> " . htmlspecialchars($loopName) . " (Loop ID: " . htmlspecialchars($loopId) . ")</p>"
         . "<p><strong>HUD file:</strong> " . htmlspecialchars($hudFile['name']) . "</p>"
         . "<p><strong>Check file:</strong> " . htmlspecialchars($checkFile['name']) . "</p>"
         . "<p><strong>DotLoop:</strong> " . htmlspecialchars($dlStatus) . "</p>"
         . "<p><strong>Submitted:</strong> " . date('F j, Y \a\t g:i A') . "</p>"
         . "<hr><p style='font-size:12px;color:#666'>Submission ID #{$submissionId} — files stored on server at data/hud_uploads/</p>";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: AgentEdge <noreply@innovateonline.com>\r\n";
$headers .= "Reply-To: " . htmlspecialchars($agent['email']) . "\r\n";

$emailSent = @mail('michele@innovateonline.com', $subject, $body, $headers);

// Update email_sent flag
local_db()->prepare("UPDATE hud_submissions SET email_sent=? WHERE id=?")
    ->execute([$emailSent ? 1 : 0, $submissionId]);

echo json_encode([
    'ok'         => true,
    'dotloop_ok' => $dlOk,
    'email_sent' => $emailSent,
    'notes'      => $dlNotes,
    'id'         => $submissionId,
]);
