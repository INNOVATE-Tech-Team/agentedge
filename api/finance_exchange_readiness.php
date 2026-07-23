<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!is_super_admin()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db     = local_db();

switch ($action) {

    case 'update':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'id is required']); exit; }

        $sets = [];
        $vals = [];
        if (array_key_exists('status', $body)) {
            $status = $body['status'];
            if (!in_array($status, ['pending', 'in_progress', 'complete'], true)) {
                echo json_encode(['ok' => false, 'error' => 'invalid status']); exit;
            }
            $sets[] = 'status = ?'; $vals[] = $status;
            // Stamp/clear completed_date alongside status, same as the original UI.
            $sets[] = 'completed_date = ?';
            $vals[] = $status === 'complete' ? date('Y-m-d') : null;
        }
        if (array_key_exists('notes', $body)) {
            $sets[] = 'notes = ?'; $vals[] = trim($body['notes']) !== '' ? $body['notes'] : null;
        }
        if (!$sets) { echo json_encode(['ok' => false, 'error' => 'nothing to update']); exit; }

        $vals[] = $id;
        $stmt = $db->prepare("UPDATE exchange_milestones SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($vals);
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
