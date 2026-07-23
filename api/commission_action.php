<?php
// POST handler: log a commission check submission (any method), upload to
// DotLoop when method=upload, and notify Michele.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/dotloop.php';
require_once __DIR__ . '/../lib/notifications.php';

header('Content-Type: application/json');

function json_err(string $msg): void {
    echo json_encode(['ok' => false, 'error' => $msg]); exit;
}

$agent = current_agent();
if (!$agent) json_err('Not logged in');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required');

$loopId   = trim($_POST['loop_id']   ?? '');
$loopName = trim($_POST['loop_name'] ?? '');
$method   = trim($_POST['method']    ?? '');

if ($loopId === '')   json_err('No transaction selected');
if ($loopName === '') $loopName = 'Unknown transaction';

$validMethods = ['ach_requested', 'wire_requested', 'dropoff', 'mail', 'upload'];
if (!in_array($method, $validMethods, true)) json_err('Invalid method');

$officeLocation = null;
if ($method === 'dropoff') {
    $officeLocation = trim($_POST['office_location'] ?? '');
    if (!in_array($officeLocation, ['MI', 'Pro Dr', 'NMB'], true)) {
        json_err('Please select a valid office');
    }
}

// ── Handle the upload method: validate + store the file ──────────────────────
$checkOriginal = null;
$checkStored   = null;
$dlOk          = false;
$dlNotes       = [];
$dlCheckDocId  = null;
$dlFolderId    = null;

if ($method === 'upload') {
    $allowed  = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxBytes = 20 * 1024 * 1024; // 20 MB

    if (empty($_FILES['check_file']) || $_FILES['check_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['check_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msgs = [
            UPLOAD_ERR_INI_SIZE  => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_NO_FILE   => 'No file uploaded',
        ];
        json_err('Check file: ' . ($msgs[$code] ?? 'Upload error'));
    }
    $f = $_FILES['check_file'];
    if ($f['size'] > $maxBytes) json_err('Check file exceeds 20 MB limit');
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allowed, true)) json_err('Only PDF and image files are accepted');

    $uploadDir = __DIR__ . '/../data/commission_check_uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0750, true);

    $prefix  = date('Ymd_His') . '_' . substr(md5($agent['email']), 0, 6);
    $ext     = pathinfo($f['name'], PATHINFO_EXTENSION);
    $checkStored = $prefix . '_check.' . ($ext ?: 'pdf');
    if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $checkStored)) {
        json_err('Could not save check file');
    }
    $checkOriginal = $f['name'];

    // ── Upload to DotLoop ──────────────────────────────────────────────────────
    $email     = $agent['email'];
    $connected = dotloop_is_connected($email);
    $tokens    = $connected ? dotloop_get_tokens($email) : null;
    $profileId = $tokens['profile_id'] ?? '';

    if ($connected && $profileId !== '') {
        $fResult = dotloop_get_folders($email, $profileId, $loopId);
        if ($fResult['ok'] && !empty($fResult['data'])) {
            $folders     = $fResult['data'];
            $checkFolder = dotloop_pick_folder($folders, 'check');

            $checkUp = dotloop_upload_document(
                $email, $profileId, $loopId,
                $checkFolder,
                $uploadDir . '/' . $checkStored,
                $checkOriginal,
                $mime
            );
            if ($checkUp['ok']) {
                $dlCheckDocId = $checkUp['data']['data']['id'] ?? null;
                $dlFolderId   = $checkFolder;
                $dlOk         = true;
            } else {
                $dlNotes[] = 'Check upload to DotLoop failed: ' . $checkUp['error'];
            }
        } else {
            $dlNotes[] = 'Could not retrieve loop folders from DotLoop';
        }
    } else {
        $dlNotes[] = 'Agent not connected to DotLoop — file saved locally only';
    }
}

// ── Log submission ────────────────────────────────────────────────────────────
local_db()->prepare(
    "INSERT INTO commission_check_submissions
        (agent_email, agent_name, loop_id, loop_name, method, office_location,
         check_original, check_stored, dl_check_doc_id, dl_folder_id,
         dotloop_ok, email_sent, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)"
)->execute([
    $agent['email'],
    $agent['name'] ?? $agent['email'],
    $loopId,
    $loopName,
    $method,
    $officeLocation,
    $checkOriginal,
    $checkStored,
    $dlCheckDocId,
    $dlFolderId,
    $dlOk ? 1 : 0,
    implode('; ', $dlNotes) ?: null,
]);
$submissionId = local_db()->lastInsertId();

// ── Notify Michele ────────────────────────────────────────────────────────────
$agentName    = $agent['name'] ?? $agent['email'];
$methodLabels = [
    'ach_requested' => 'ACH / Wire requested (preferred)',
    'wire_requested' => 'Wire requested',
    'dropoff'       => 'Dropped off at an office' . ($officeLocation ? " ({$officeLocation})" : ''),
    'mail'          => 'Mailed',
    'upload'        => 'Scanned check uploaded',
];
$subject = "Commission Check — {$agentName} — {$loopName}";
$lines   = [
    "Commission Check Submission",
    "",
    "Agent:        {$agentName} ({$agent['email']})",
    "Transaction:  {$loopName} (Loop ID: {$loopId})",
    "Method:       " . ($methodLabels[$method] ?? $method),
];
if ($method === 'upload') {
    $lines[] = "Check file:   {$checkOriginal}";
    $lines[] = "DotLoop:      " . ($dlOk ? 'Successfully uploaded' : 'Could not upload — ' . implode(' ', $dlNotes));
}
$lines[] = "";
$lines[] = "Submitted: " . date('F j, Y \a\t g:i A');
$lines[] = "";
$lines[] = "Submission ID #{$submissionId}";

$emailQueued = queue_email_to(['michele@innovateonline.com'], $subject, implode("\n", $lines), $agent['email'], $agent['name'] ?? '') > 0;

local_db()->prepare("UPDATE commission_check_submissions SET email_sent=? WHERE id=?")
    ->execute([$emailQueued ? 1 : 0, $submissionId]);

echo json_encode([
    'ok'         => true,
    'dotloop_ok' => $dlOk,
    'email_sent' => $emailQueued,
    'notes'      => $dlNotes,
    'id'         => $submissionId,
]);

// Close the HTTP response, then actually send the queued email.
dispatch_notification_queue();
