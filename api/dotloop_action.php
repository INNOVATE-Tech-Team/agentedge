<?php
// DotLoop — POST action endpoint. Returns JSON.
// All calls use the shared admin DotLoop connection (see dotloop_shared_email()
// in lib/dotloop.php) rather than the viewing agent's own token — agents don't
// have individual DotLoop connections. Access is instead checked per-loop
// against the local participant cache, so an agent can only touch loops
// they're actually on.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/dotloop.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$email       = strtolower(trim($agent['email']));
$sharedEmail = dotloop_shared_email();
$action      = $_GET['action'] ?? '';

/** Is $email (or any email in its dotloop_email_groups group) a participant on $loopId? */
function dotloop_agent_owns_loop(string $loopId, string $email): bool {
    $emails       = dotloop_email_group($email);
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = local_db()->prepare(
        "SELECT 1 FROM dotloop_loop_participants WHERE loop_id = ? AND email IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$loopId], $emails));
    return (bool)$stmt->fetchColumn();
}

// Remaining actions require a JSON body
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── action=update_loop_detail ─────────────────────────────────────────────────
if ($action === 'update_loop_detail') {
    $loopId    = (string)($body['loop_id']            ?? '');
    $profileId = (string)($body['profile_id']         ?? '');
    $closing   = $body['closing_date']                ?? null;
    $price     = isset($body['purchase_price'])       ? (float)$body['purchase_price']       : null;
    $listComm  = isset($body['listing_commission'])   ? (float)$body['listing_commission']   : null;
    $sellComm  = isset($body['selling_commission'])   ? (float)$body['selling_commission']   : null;

    if ($loopId === '' || $profileId === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing loop_id or profile_id']);
        exit;
    }

    if (!dotloop_agent_owns_loop($loopId, $email)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not a participant on this transaction']);
        exit;
    }

    $payload = [];
    if ($closing  !== null) $payload['closing_date']                = $closing;
    if ($price    !== null) $payload['purchase_price']              = $price;
    if ($listComm !== null) $payload['listing_commission_amount']   = $listComm;
    if ($sellComm !== null) $payload['selling_commission_amount']   = $sellComm;

    if (empty($payload)) {
        echo json_encode(['ok' => false, 'error' => 'No fields to update']);
        exit;
    }

    $result = dotloop_api($sharedEmail, 'PATCH', "/profile/{$profileId}/loop/{$loopId}/detail", $payload);
    echo json_encode($result['ok']
        ? ['ok' => true]
        : ['ok' => false, 'error' => $result['error'] ?? 'Update failed']
    );
    exit;
}

// ── action=get_folders ────────────────────────────────────────────────────────
if ($action === 'get_folders') {
    $loopId    = (string)($body['loop_id']    ?? '');
    $profileId = (string)($body['profile_id'] ?? '');

    if ($loopId === '' || $profileId === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing loop_id or profile_id']);
        exit;
    }

    if (!dotloop_agent_owns_loop($loopId, $email)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not a participant on this transaction']);
        exit;
    }

    $result = dotloop_api($sharedEmail, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder");
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to load folders']);
        exit;
    }

    $folders = $result['data']['data'] ?? $result['data'] ?? [];
    echo json_encode(['ok' => true, 'folders' => $folders]);
    exit;
}

// ── action=get_documents ──────────────────────────────────────────────────────
if ($action === 'get_documents') {
    $loopId    = (string)($body['loop_id']    ?? '');
    $profileId = (string)($body['profile_id'] ?? '');
    $folderId  = (string)($body['folder_id']  ?? '');

    if ($loopId === '' || $profileId === '' || $folderId === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing loop_id, profile_id, or folder_id']);
        exit;
    }

    if (!dotloop_agent_owns_loop($loopId, $email)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not a participant on this transaction']);
        exit;
    }

    $result = dotloop_api($sharedEmail, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder/{$folderId}/document");
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to load documents']);
        exit;
    }

    $docs = $result['data']['data'] ?? $result['data'] ?? [];
    echo json_encode(['ok' => true, 'documents' => $docs]);
    exit;
}

// ── action=upload_document ────────────────────────────────────────────────────
// multipart/form-data, not JSON — fields come from $_POST/$_FILES, not $body.
if ($action === 'upload_document') {
    $loopId    = trim($_POST['loop_id']    ?? '');
    $profileId = trim($_POST['profile_id'] ?? '');
    $folderId  = trim($_POST['folder_id']  ?? '');

    if ($loopId === '' || $profileId === '' || $folderId === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing loop_id, profile_id, or folder_id']);
        exit;
    }
    if (!dotloop_agent_owns_loop($loopId, $email)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not a participant on this transaction']);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded, or upload error']);
        exit;
    }
    $maxBytes = 20 * 1024 * 1024; // 20 MB, matches api/hud_action.php's limit
    if ($_FILES['file']['size'] > $maxBytes) {
        echo json_encode(['ok' => false, 'error' => 'File exceeds 20 MB limit']);
        exit;
    }
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $mime = mime_content_type($_FILES['file']['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        echo json_encode(['ok' => false, 'error' => 'File type not allowed']);
        exit;
    }

    $result = dotloop_upload_document(
        $sharedEmail, $profileId, $loopId, $folderId,
        $_FILES['file']['tmp_name'], $_FILES['file']['name'], $mime
    );
    echo json_encode($result['ok']
        ? ['ok' => true]
        : ['ok' => false, 'error' => $result['error'] ?? 'Upload failed']
    );
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES)]);
