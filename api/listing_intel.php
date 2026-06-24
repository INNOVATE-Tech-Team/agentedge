<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');
$agent = require_login();
$me    = $agent['email'];
$db    = local_db();

function je(array $d): never { echo json_encode($d); exit; }
function err(string $msg, int $code = 400): never { http_response_code($code); je(['ok' => false, 'error' => $msg]); }

// ── GET requests (read-only) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_outreach') {
        $pid = (int)($_GET['prospect_id'] ?? 0);
        if (!$pid) err('Missing prospect_id');
        // Verify ownership
        $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
        $s->execute([$pid, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $s = $db->prepare("SELECT * FROM listing_outreach WHERE prospect_id=? ORDER BY logged_at DESC LIMIT 50");
        $s->execute([$pid]);
        je(['ok' => true, 'items' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    err('Unknown action');
}

// ── POST requests (writes) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method not allowed', 405);

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) err('Invalid JSON');

$action = $body['action'] ?? '';

// ── save_farm ──────────────────────────────────────────────────────────────────
if ($action === 'save_farm') {
    $id    = (int)($body['id'] ?? 0);
    $name  = trim($body['name'] ?? '');
    if (!$name) err('Farm name is required');
    $zips  = array_values(array_filter(array_map('trim', (array)($body['zip_codes']  ?? []))));
    $hoods = array_values(array_filter(array_map('trim', (array)($body['neighborhoods'] ?? []))));
    $notes = trim($body['notes'] ?? '');

    if ($id) {
        // Verify ownership
        $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
        $s->execute([$id, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $s = $db->prepare("UPDATE listing_farms SET name=?,zip_codes=?,neighborhoods=?,notes=? WHERE id=? AND agent_email=?");
        $s->execute([$name, json_encode($zips), json_encode($hoods), $notes, $id, $me]);
        je(['ok' => true, 'id' => $id]);
    } else {
        $s = $db->prepare("INSERT INTO listing_farms (agent_email,name,zip_codes,neighborhoods,notes) VALUES (?,?,?,?,?)");
        $s->execute([$me, $name, json_encode($zips), json_encode($hoods), $notes]);
        je(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

// ── delete_farm ────────────────────────────────────────────────────────────────
if ($action === 'delete_farm') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) err('Missing id');
    $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
    $s->execute([$id, $me]);
    if (!$s->fetch()) err('Not found', 404);
    // Unlink prospects but keep them
    $db->prepare("UPDATE listing_prospects SET farm_id=NULL WHERE farm_id=? AND agent_email=?")->execute([$id, $me]);
    $db->prepare("DELETE FROM listing_farms WHERE id=? AND agent_email=?")->execute([$id, $me]);
    je(['ok' => true]);
}

// ── save_prospect ──────────────────────────────────────────────────────────────
if ($action === 'save_prospect') {
    $id     = (int)($body['id'] ?? 0);
    $owner  = trim($body['owner_name'] ?? '');
    $addr   = trim($body['address'] ?? '');
    if (!$owner) err('Owner name is required');
    if (!$addr)  err('Address is required');

    $farmId = $body['farm_id'] ? (int)$body['farm_id'] : null;
    // Validate farm belongs to me
    if ($farmId) {
        $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
        $s->execute([$farmId, $me]);
        if (!$s->fetch()) $farmId = null;
    }

    $allowed_sources = ['manual','expired','fsbo','equity'];
    $allowed_statuses = ['new','contacted','active','dead'];
    $source = in_array($body['source'] ?? '', $allowed_sources) ? $body['source'] : 'manual';
    $status = in_array($body['status'] ?? '', $allowed_statuses) ? $body['status'] : 'new';
    $score  = max(0, min(100, (int)($body['seller_score'] ?? 0)));
    $value  = max(0, (int)($body['est_value'] ?? 0));
    $city   = trim($body['city'] ?? '');
    $zip    = trim($body['zip'] ?? '');
    $phone  = trim($body['phone'] ?? '');
    $email  = trim($body['email'] ?? '');
    $mls    = trim($body['mls_number'] ?? '');
    $notes  = trim($body['notes'] ?? '');

    if ($id) {
        $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
        $s->execute([$id, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $s = $db->prepare("UPDATE listing_prospects
            SET farm_id=?,owner_name=?,address=?,city=?,zip=?,phone=?,email=?,
                source=?,status=?,seller_score=?,est_value=?,mls_number=?,notes=?,updated_at=datetime('now')
            WHERE id=? AND agent_email=?");
        $s->execute([$farmId,$owner,$addr,$city,$zip,$phone,$email,$source,$status,$score,$value,$mls,$notes,$id,$me]);
        je(['ok' => true, 'id' => $id]);
    } else {
        $s = $db->prepare("INSERT INTO listing_prospects
            (agent_email,farm_id,owner_name,address,city,zip,phone,email,source,status,seller_score,est_value,mls_number,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([$me,$farmId,$owner,$addr,$city,$zip,$phone,$email,$source,$status,$score,$value,$mls,$notes]);
        je(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

// ── log_outreach ───────────────────────────────────────────────────────────────
if ($action === 'log_outreach') {
    $pid = (int)($body['prospect_id'] ?? 0);
    if (!$pid) err('Missing prospect_id');
    // Verify ownership
    $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
    $s->execute([$pid, $me]);
    if (!$s->fetch()) err('Not found', 404);

    $allowed_methods  = ['call','text','email','mail','door'];
    $allowed_outcomes = ['no_answer','left_vm','spoke','interested','not_interested','other'];
    $method  = in_array($body['method']  ?? '', $allowed_methods)  ? $body['method']  : 'call';
    $outcome = in_array($body['outcome'] ?? '', $allowed_outcomes) ? $body['outcome'] : 'other';
    $notes   = trim($body['notes'] ?? '');

    $s = $db->prepare("INSERT INTO listing_outreach (prospect_id,agent_email,method,outcome,notes) VALUES (?,?,?,?,?)");
    $s->execute([$pid,$me,$method,$outcome,$notes]);

    // Update last_contact on prospect
    $db->prepare("UPDATE listing_prospects SET last_contact=date('now'),updated_at=datetime('now') WHERE id=? AND agent_email=?")
       ->execute([$pid,$me]);

    // If outcome = interested or spoke, bump status to active
    if (in_array($outcome, ['interested','spoke'])) {
        $db->prepare("UPDATE listing_prospects SET status='active',updated_at=datetime('now') WHERE id=? AND agent_email=? AND status='new'")
           ->execute([$pid,$me]);
    }

    je(['ok' => true]);
}

err('Unknown action');
