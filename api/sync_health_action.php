<?php
// Delete / bulk-delete tblstaff rows from the Sync Health report.
// Gated to super_admin only — this is a permanent, hard DELETE against
// Perfex's live tblstaff table via db_perfex_write() (see db.php), not a
// soft-hide. There is no undo.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent || !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$emails = array_values(array_filter(array_map(
    fn($e) => strtolower(trim((string)$e)),
    $body['emails'] ?? []
)));

if (!$emails) {
    echo json_encode(['ok' => false, 'error' => 'No emails provided']);
    exit;
}

$m = db_perfex_write();
$placeholders = implode(',', array_fill(0, count($emails), '?'));
$stmt = $m->prepare("DELETE FROM tblstaff WHERE LOWER(email) IN ({$placeholders})");
$stmt->bind_param(str_repeat('s', count($emails)), ...$emails);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

echo json_encode(['ok' => true, 'deleted' => $deleted]);
