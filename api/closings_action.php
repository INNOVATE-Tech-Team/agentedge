<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/closings.php';

header('Content-Type: application/json');
$agent = require_login();
if (!$agent) { echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }

$in      = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $in['action'] ?? '';
$db      = local_db();
$myEmail = strtolower(trim($agent['email'] ?? ''));

if ($action === 'save') {
    $id     = (int)($in['id'] ?? 0);
    $teamId = (int)($in['team_id'] ?? 0);

    // On edit, gate against (and pin to) the record's own team/agent — an
    // edit can't be used to move a closing you can't otherwise touch.
    $existing = null;
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM closings WHERE id=?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { echo json_encode(['ok' => false, 'error' => 'Closing not found']); exit; }
        $teamId     = (int)$existing['team_id'];
        $agentEmail = $existing['agent_email'];
        if (!can_edit_closing($teamId, $agentEmail)) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }
    } else {
        $agentEmail = strtolower(trim($in['agent_email'] ?? '')) ?: $myEmail;
        if (!$teamId) { echo json_encode(['ok' => false, 'error' => 'team_id is required']); exit; }
        if (!can_edit_closing($teamId, $agentEmail)) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }
    }

    $propertyAddress = trim($in['property_address'] ?? '');
    $salePrice       = (float)($in['sale_price'] ?? 0);
    $contractDate    = trim($in['contract_date'] ?? '');
    $closingDateEst  = trim($in['closing_date_est'] ?? '');
    $closingDateAct  = trim($in['closing_date_actual'] ?? '');
    $clientNames     = trim($in['client_names'] ?? '');
    $clientEmail     = trim($in['client_email'] ?? '');
    $commissionRate  = (float)($in['commission_rate'] ?? 0);
    $bonusAmount     = (float)($in['bonus_amount'] ?? 0);
    $dwtPercent      = (float)($in['dwt_percent'] ?? 0);
    $refPercent      = (float)($in['outgoing_referral_percent'] ?? 0);
    $status          = in_array($in['status'] ?? '', ['pending', 'closed', 'fell_through'], true) ? $in['status'] : 'pending';

    // Lead source: an explicit manual pick always wins. Otherwise, try a FUB
    // lookup by client email — but only when we don't already have a FUB
    // contact matched (so re-saving an edited closing doesn't re-hit the API
    // or clobber a source FUB has since changed underneath it). Falls back to
    // whatever was already stored, or 'unknown' on create.
    $manualSource  = trim($in['lead_source'] ?? '');
    $leadSource    = $existing['lead_source'] ?? 'unknown';
    $leadSourceRaw = $existing['lead_source_raw'] ?? '';
    $fubContactId  = $existing['fub_contact_id'] ?? '';
    if ($manualSource !== '') {
        $leadSource    = normalize_lead_source($manualSource);
        $leadSourceRaw = $manualSource;
    } elseif ($clientEmail !== '' && empty($fubContactId)) {
        $person = fub_lookup_person($clientEmail);
        if ($person && $person['source'] !== '') {
            $leadSource    = normalize_lead_source($person['source']);
            $leadSourceRaw = $person['source'];
            $fubContactId  = $person['id'];
        }
    }

    if ($id) {
        $db->prepare("UPDATE closings SET
                property_address=?, sale_price=?, contract_date=?, closing_date_est=?, closing_date_actual=?,
                client_names=?, client_email=?, lead_source=?, lead_source_raw=?, commission_rate=?, bonus_amount=?,
                dwt_percent=?, outgoing_referral_percent=?, status=?, fub_contact_id=?, updated_at=datetime('now')
            WHERE id=?")
            ->execute([
                $propertyAddress, $salePrice, $contractDate, $closingDateEst, $closingDateAct,
                $clientNames, $clientEmail, $leadSource, $leadSourceRaw, $commissionRate, $bonusAmount,
                $dwtPercent, $refPercent, $status, $fubContactId, $id,
            ]);
    } else {
        $db->prepare("INSERT INTO closings
                (team_id, agent_email, property_address, sale_price, contract_date, closing_date_est, closing_date_actual,
                 client_names, client_email, lead_source, lead_source_raw, commission_rate, bonus_amount, dwt_percent,
                 outgoing_referral_percent, status, source_system, fub_contact_id, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'manual',?,?)")
            ->execute([
                $teamId, $agentEmail, $propertyAddress, $salePrice, $contractDate, $closingDateEst, $closingDateAct,
                $clientNames, $clientEmail, $leadSource, $leadSourceRaw, $commissionRate, $bonusAmount, $dwtPercent,
                $refPercent, $status, $fubContactId, $myEmail,
            ]);
        $id = (int)$db->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $id, 'lead_source' => $leadSource]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Id required']); exit; }
    $stmt = $db->prepare("SELECT team_id, agent_email FROM closings WHERE id=?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { echo json_encode(['ok' => true]); exit; }
    if (!can_edit_closing((int)$existing['team_id'], $existing['agent_email'])) {
        echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
    }
    $db->prepare("DELETE FROM closings WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'save_split_rule') {
    $teamId     = (int)($in['team_id'] ?? 0);
    $agentEmail = strtolower(trim($in['agent_email'] ?? ''));
    if (!$teamId) { echo json_encode(['ok' => false, 'error' => 'team_id required']); exit; }
    if (!can_manage_split_rules($teamId)) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

    $rate = (float)($in['default_commission_rate'] ?? 0);
    $dwt  = (float)($in['default_dwt_percent'] ?? 0);
    $db->prepare(
        "INSERT INTO team_split_rules (team_id, agent_email, default_commission_rate, default_dwt_percent)
         VALUES (?,?,?,?)
         ON CONFLICT(team_id, agent_email) DO UPDATE SET
            default_commission_rate=excluded.default_commission_rate,
            default_dwt_percent=excluded.default_dwt_percent,
            updated_at=datetime('now')"
    )->execute([$teamId, $agentEmail, $rate, $dwt]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
