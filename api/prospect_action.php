<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/geocode.php';

header('Content-Type: application/json');
$agent = require_login();
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

$validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'signed'];

if ($action === 'save') {
    $id         = (int)($in['id'] ?? 0);
    $name       = trim($in['full_name']         ?? '');
    $brokerage  = trim($in['current_brokerage'] ?? '');
    $phone      = trim($in['phone']             ?? '');
    $email      = strtolower(trim($in['email']  ?? ''));
    $address    = trim($in['address'] ?? '');
    $city       = trim($in['city']    ?? '');
    $state      = strtoupper(preg_replace('/[^A-Za-z]/', '', $in['state'] ?? ''));
    $zip        = trim($in['zip']     ?? '');
    $status     = trim($in['status']  ?? 'new');
    $notes      = trim($in['notes']   ?? '');

    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }
    if (!in_array($status, $validStatuses, true)) $status = 'new';

    try {
        $oldRow = null;
        if ($id) {
            $s = $db->prepare("SELECT address, city, state, zip, lat, lng, geocoded_at FROM recruit_prospects WHERE id=?");
            $s->execute([$id]);
            $oldRow = $s->fetch(PDO::FETCH_ASSOC);
        }

        // Only re-geocode when the address actually changed, to avoid hammering the Census API
        // on every unrelated field edit (e.g. just updating notes or status).
        $addressChanged = !$oldRow
            || $oldRow['address'] !== $address || $oldRow['city'] !== $city
            || $oldRow['state']   !== $state   || $oldRow['zip']  !== $zip;
        $lat = $oldRow['lat'] ?? null;
        $lng = $oldRow['lng'] ?? null;
        $geocodedAt = $oldRow['geocoded_at'] ?? '';
        $geocodeOk  = true;
        if ($addressChanged) {
            if ($address === '' && $city === '' && $zip === '') {
                $lat = null; $lng = null; $geocodedAt = '';
            } else {
                $coords = geocode_address($address, $city, $state, $zip);
                if ($coords) {
                    $lat = $coords['lat']; $lng = $coords['lng'];
                    $geocodedAt = date('Y-m-d H:i:s');
                } else {
                    $lat = null; $lng = null; $geocodedAt = '';
                    $geocodeOk = false;
                }
            }
        }

        if ($id) {
            $db->prepare(
                "UPDATE recruit_prospects SET
                    full_name=?, current_brokerage=?, phone=?, email=?, address=?, city=?, state=?, zip=?,
                    lat=?, lng=?, geocoded_at=?, status=?, notes=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$name, $brokerage, $phone, $email, $address, $city, $state, $zip, $lat, $lng, $geocodedAt, $status, $notes, $id]);
        } else {
            $db->prepare(
                "INSERT INTO recruit_prospects
                    (full_name, current_brokerage, phone, email, address, city, state, zip, lat, lng, geocoded_at, status, notes, added_at, added_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), ?, datetime('now'))"
            )->execute([$name, $brokerage, $phone, $email, $address, $city, $state, $zip, $lat, $lng, $geocodedAt, $status, $notes, $agent['email'] ?? '']);
            $id = (int)$db->lastInsertId();
        }

        echo json_encode(['ok'=>true, 'id'=>$id, 'geocode_ok'=>$geocodeOk]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Id required']); exit; }
    $db->prepare("DELETE FROM recruit_prospects WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
