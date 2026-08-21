<?php
// CRUD for mls_referral_coverage — the MLS association + coverage-area list
// edited on backoffice_referral.php and consumed read-only by
// api/referral_coverage.php for the Agent Roster's referral-location filter.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

$db = local_db();

// GET — list all rows for the builder table.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query("SELECT * FROM mls_referral_coverage ORDER BY mls_name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'items'=>array_map('row_payload', $rows)]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function row_payload(array $r): array {
    return [
        'id'        => (int)$r['id'],
        'mls_name'  => $r['mls_name'],
        'counties'  => $r['counties'],
        'cities'    => $r['cities'],
        'townships' => $r['townships'],
        'zips'      => $r['zips'],
        'notes'     => $r['notes'],
    ];
}

switch ($action) {

    case 'add':
        $name = trim($body['mls_name'] ?? '');
        if ($name === '') { echo json_encode(['ok'=>false,'error'=>'MLS/association name is required']); exit; }
        try {
            $s = $db->prepare(
                "INSERT INTO mls_referral_coverage (mls_name,counties,cities,townships,zips,notes)
                 VALUES (?,?,?,?,?,?)"
            );
            $s->execute([
                $name,
                trim($body['counties']  ?? ''),
                trim($body['cities']    ?? ''),
                trim($body['townships'] ?? ''),
                trim($body['zips']      ?? ''),
                trim($body['notes']     ?? ''),
            ]);
            $id = (int)$db->lastInsertId();
            $row = $db->prepare("SELECT * FROM mls_referral_coverage WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok'=>true, 'item'=>row_payload($row->fetch(PDO::FETCH_ASSOC))]);
        } catch (\Exception $e) {
            echo json_encode(['ok'=>false,'error'=>'An MLS/association with that name already exists.']);
        }
        break;

    case 'update':
        $id   = (int)($body['id'] ?? 0);
        $name = trim($body['mls_name'] ?? '');
        if (!$id || $name === '') { echo json_encode(['ok'=>false,'error'=>'id and mls_name are required']); exit; }
        try {
            $s = $db->prepare(
                "UPDATE mls_referral_coverage
                 SET mls_name=?, counties=?, cities=?, townships=?, zips=?, notes=?, updated_at=datetime('now')
                 WHERE id=?"
            );
            $s->execute([
                $name,
                trim($body['counties']  ?? ''),
                trim($body['cities']    ?? ''),
                trim($body['townships'] ?? ''),
                trim($body['zips']      ?? ''),
                trim($body['notes']     ?? ''),
                $id,
            ]);
            echo json_encode(['ok'=>true]);
        } catch (\Exception $e) {
            echo json_encode(['ok'=>false,'error'=>'An MLS/association with that name already exists.']);
        }
        break;

    case 'delete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM mls_referral_coverage WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
