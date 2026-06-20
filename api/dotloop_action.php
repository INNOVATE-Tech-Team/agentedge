<?php
// DotLoop — POST action endpoint. Returns JSON.
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

$email  = $agent['email'];
$action = $_GET['action'] ?? '';

// ── action=disconnect ─────────────────────────────────────────────────────────
if ($action === 'disconnect') {
    local_db()->prepare("DELETE FROM dotloop_tokens WHERE agent_email = ?")->execute([$email]);
    echo json_encode(['ok' => true]);
    exit;
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

    // Verify the agent owns this profile_id
    $tokens = dotloop_get_tokens($email);
    if (!$tokens || (string)$tokens['profile_id'] !== $profileId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Profile ID does not match your connected DotLoop account']);
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

    $result = dotloop_api($email, 'PATCH', "/profile/{$profileId}/loop/{$loopId}/detail", $payload);
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

    $tokens = dotloop_get_tokens($email);
    if (!$tokens || (string)$tokens['profile_id'] !== $profileId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Profile ID mismatch']);
        exit;
    }

    $result = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder");
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

    $tokens = dotloop_get_tokens($email);
    if (!$tokens || (string)$tokens['profile_id'] !== $profileId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Profile ID mismatch']);
        exit;
    }

    $result = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder/{$folderId}/document");
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to load documents']);
        exit;
    }

    $docs = $result['data']['data'] ?? $result['data'] ?? [];
    echo json_encode(['ok' => true, 'documents' => $docs]);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES)]);
