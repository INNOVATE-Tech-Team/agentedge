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

    if ($action === 'sync_status') {
        // Summary counts for the prospects tab header
        $sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND status != 'dead'"); $sc->execute([$me]); $total = (int)$sc->fetchColumn();
        $sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND skip_traced=0 AND status != 'dead'"); $sc->execute([$me]); $needsTrace = (int)$sc->fetchColumn();
        $sc = $db->prepare("SELECT MAX(updated_at) FROM listing_prospects WHERE agent_email=? AND source='auto'"); $sc->execute([$me]); $lastSync = $sc->fetchColumn() ?: null;
        je(['ok' => true, 'total' => $total, 'needs_trace' => $needsTrace, 'last_sync' => $lastSync]);
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

// ── sync_prospects ────────────────────────────────────────────────────────────
if ($action === 'sync_prospects') {
    $cfg   = cfg();
    $base  = rtrim($cfg['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $cfg['crm_token'] ?? '';
    if (!$token) err('CRM token not configured');

    // Collect all zip codes across this agent's farms
    $sf = $db->prepare("SELECT zip_codes FROM listing_farms WHERE agent_email=?");
    $sf->execute([$me]);
    $allZips = [];
    foreach ($sf->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach ((json_decode($json, true) ?: []) as $z) {
            if ($z) $allZips[] = $z;
        }
    }
    $allZips = array_unique($allZips);
    if (empty($allZips)) err('No zip codes defined in your farms. Add farm areas first.');

    $url = $base . '/public/listing-intel/seller-candidates'
         . '?token=' . urlencode($token)
         . '&zips='  . urlencode(implode(',', $allZips))
         . '&limit=500';
    $ctx = stream_context_create(['http' => [
        'timeout'       => 30,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) err('Could not reach CRM at ' . $base . '. Check that the CRM server is running.');
    $data = json_decode($raw, true);
    if (isset($data['detail'])) err('CRM error: ' . $data['detail']);
    if (!isset($data['candidates'])) err('Unexpected CRM response: ' . substr($raw, 0, 120));

    $candidates = $data['candidates'];

    // Map zip → farm_id for linking
    $sf = $db->prepare("SELECT id, zip_codes FROM listing_farms WHERE agent_email=?");
    $sf->execute([$me]);
    $zipToFarm = [];
    foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $f) {
        foreach ((json_decode($f['zip_codes'], true) ?: []) as $z) {
            $zipToFarm[$z] = $f['id'];
        }
    }

    $inserted = 0; $updated = 0;
    $check  = $db->prepare("SELECT id, status FROM listing_prospects WHERE agent_email=? AND address=? AND zip=?");
    $insert = $db->prepare("INSERT INTO listing_prospects
        (agent_email,farm_id,owner_name,address,city,zip,source,status,
         seller_score,est_value,purchase_price,purchase_date,years_owned,velocity)
        VALUES (?,?,'',?,?,?,'auto','new',?,?,?,?,?,?)");
    $update = $db->prepare("UPDATE listing_prospects SET
        seller_score=?,est_value=?,purchase_price=?,purchase_date=?,years_owned=?,velocity=?,farm_id=?,updated_at=datetime('now')
        WHERE agent_email=? AND address=? AND zip=? AND status='new'");

    foreach ($candidates as $cand) {
        $addr  = trim($cand['address'] ?? '');
        $zip   = trim($cand['zip'] ?? '');
        if (!$addr || !$zip) continue;
        $farmId = $zipToFarm[$zip] ?? null;
        $score  = (int)($cand['seller_score'] ?? 0);
        $val    = (int)($cand['est_value'] ?? 0);
        $pp     = (int)($cand['purchase_price'] ?? 0);
        $pd     = $cand['purchase_date'] ?? '';
        $yo     = (int)($cand['years_owned'] ?? 0);
        $vel    = (int)($cand['neighborhood_velocity'] ?? 0);
        $city   = trim($cand['city'] ?? '');

        $check->execute([$me, $addr, $zip]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $insert->execute([$me, $farmId, $addr, $city, $zip, $score, $val, $pp, $pd, $yo, $vel]);
            $inserted++;
        } else {
            $update->execute([$score, $val, $pp, $pd, $yo, $vel, $farmId, $me, $addr, $zip]);
            $updated++;
        }
    }

    je(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'total' => count($candidates)]);
}

// ── mark_skip_traced ──────────────────────────────────────────────────────────
if ($action === 'mark_skip_traced') {
    $pid       = (int)($body['prospect_id'] ?? 0);
    $ownerName = trim($body['owner_name'] ?? '');
    $phone     = trim($body['phone'] ?? '');
    $email     = trim($body['email'] ?? '');
    if (!$pid) err('Missing prospect_id');
    $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
    $s->execute([$pid, $me]);
    if (!$s->fetch()) err('Not found', 404);
    $db->prepare("UPDATE listing_prospects SET
        skip_traced=1, skip_traced_at=datetime('now'),
        owner_name=CASE WHEN ?!='' THEN ? ELSE owner_name END,
        phone=CASE WHEN ?!='' THEN ? ELSE phone END,
        email=CASE WHEN ?!='' THEN ? ELSE email END,
        updated_at=datetime('now')
        WHERE id=? AND agent_email=?")
       ->execute([$ownerName,$ownerName,$phone,$phone,$email,$email,$pid,$me]);
    je(['ok' => true]);
}

err('Unknown action');
