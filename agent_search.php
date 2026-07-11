<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!can_search_network()) { echo json_encode(['agents' => []]); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['agents' => []]); exit; }

$like = '%' . $q . '%';
$rows = db_query(
    "SELECT firstname, lastname, email
     FROM tblstaff
     WHERE firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname,' ',lastname) LIKE ?
     ORDER BY firstname, lastname
     LIMIT 20",
    [$like, $like, $like]
);

$agents = array_map(fn($r) => [
    'name'  => trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')),
    'email' => $r['email'] ?? '',
], $rows);

echo json_encode(['agents' => $agents]);
