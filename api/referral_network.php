<?php
// Referral Network API — an agent's private outside-market partner list,
// referral logs against each partner, and the company-wide "need a partner
// in X" request board.
//
// GET  (no action)              → bootstrap payload: metros + my partners (with leads) + all requests (with responses)
// GET  action=open_requests     → lightweight feed of open requests for the dashboard widget
// POST action=save_partner      → create/update one of MY partners
// POST action=delete_partner    → delete one of MY partners (cascades its leads)
// POST action=save_lead         → create/update a referral log entry against one of MY partners
// POST action=delete_lead       → delete a referral log entry (must own the parent partner)
// POST action=create_request    → post a new company-wide request
// POST action=close_request     → close one of MY OWN requests
// POST action=respond_request   → respond to (any) open request, optionally sharing one of MY
//                                  partners; notifies the requester either way
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/referral_network.php';

function rn_json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$myEmail = strtolower(trim($agent['email'] ?? ''));
$myName  = trim($agent['name'] ?? $myEmail);
$db      = local_db();

// ── GET: lightweight open-requests feed for the dashboard widget ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'open_requests') {
    header('Content-Type: application/json');
    $rows = $db->query(
        "SELECT r.id, r.agent_email, r.referral_type, r.notes, r.created_at, m.metro_name, m.state_code,
                COALESCE(NULLIF(i.full_name, ''), r.agent_email) AS agent_name
         FROM referral_requests r
         JOIN referral_metros m ON m.id = r.metro_id
         LEFT JOIN agent_intake i ON i.email = r.agent_email
         WHERE r.status = 'open'
         ORDER BY r.created_at DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    rn_json_out(['ok' => true, 'requests' => $rows]);
}

// ── GET: bootstrap ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $metros = $db->query(
        "SELECT id, state_code, state_name, metro_name FROM referral_metros ORDER BY state_name, sort_ord"
    )->fetchAll(PDO::FETCH_ASSOC);

    $partners = $db->prepare(
        "SELECT p.*, m.metro_name, m.state_code
         FROM referral_partners p
         JOIN referral_metros m ON m.id = p.metro_id
         WHERE p.agent_email = ?
         ORDER BY p.name"
    );
    $partners->execute([$myEmail]);
    $partnerRows = $partners->fetchAll(PDO::FETCH_ASSOC);

    if ($partnerRows) {
        $ids  = array_column($partnerRows, 'id');
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $lst  = $db->prepare("SELECT * FROM referral_leads WHERE partner_id IN ($ph) ORDER BY created_at DESC");
        $lst->execute($ids);
        $leadsByPartner = [];
        foreach ($lst->fetchAll(PDO::FETCH_ASSOC) as $l) { $leadsByPartner[$l['partner_id']][] = $l; }
        foreach ($partnerRows as &$p) { $p['leads'] = $leadsByPartner[$p['id']] ?? []; }
        unset($p);
    }

    $requests = $db->query(
        "SELECT r.*, m.metro_name, m.state_code
         FROM referral_requests r
         JOIN referral_metros m ON m.id = r.metro_id
         ORDER BY (r.status = 'open') DESC, r.created_at DESC
         LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($requests) {
        $ids = array_column($requests, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rst = $db->prepare("SELECT * FROM referral_request_responses WHERE request_id IN ($ph) ORDER BY created_at");
        $rst->execute($ids);
        $respByRequest = [];
        foreach ($rst->fetchAll(PDO::FETCH_ASSOC) as $r) { $respByRequest[$r['request_id']][] = $r; }
        foreach ($requests as &$r) {
            $r['responses'] = $respByRequest[$r['id']] ?? [];
            $r['mine']      = strtolower($r['agent_email']) === $myEmail;
        }
        unset($r);
    }

    rn_json_out(['ok' => true, 'me' => $myEmail, 'metros' => $metros, 'partners' => $partnerRows ?: [], 'requests' => $requests ?: []]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}
header('Content-Type: application/json');
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

// ── Partners ───────────────────────────────────────────────────────────────────
if ($action === 'save_partner') {
    $id       = (int)($in['id'] ?? 0);
    $name     = trim($in['name'] ?? '');
    $company  = trim($in['company'] ?? '');
    $metroId  = (int)($in['metro_id'] ?? 0);
    $phone    = trim($in['phone'] ?? '');
    $email    = trim($in['email'] ?? '');
    $specialty= trim($in['specialty'] ?? '');
    $howMet   = trim($in['how_met'] ?? '');
    $notes    = trim($in['notes'] ?? '');

    if ($name === '') rn_json_out(['ok' => false, 'error' => 'Name is required'], 400);
    if ($metroId <= 0) rn_json_out(['ok' => false, 'error' => 'Market is required'], 400);
    $mchk = $db->prepare("SELECT 1 FROM referral_metros WHERE id=?");
    $mchk->execute([$metroId]);
    if (!$mchk->fetchColumn()) rn_json_out(['ok' => false, 'error' => 'Unknown market'], 400);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) rn_json_out(['ok' => false, 'error' => 'Invalid email'], 400);

    if ($id > 0) {
        $own = $db->prepare("SELECT agent_email FROM referral_partners WHERE id=?");
        $own->execute([$id]);
        $ownerEmail = $own->fetchColumn();
        if ($ownerEmail === false) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
        if (strtolower($ownerEmail) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        $db->prepare(
            "UPDATE referral_partners SET name=?, company=?, metro_id=?, phone=?, email=?, specialty=?, how_met=?, notes=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$name, $company, $metroId, $phone, $email, $specialty, $howMet, $notes, $id]);
        rn_json_out(['ok' => true, 'id' => $id]);
    }

    $db->prepare(
        "INSERT INTO referral_partners (agent_email, name, company, metro_id, phone, email, specialty, how_met, notes)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([$myEmail, $name, $company, $metroId, $phone, $email, $specialty, $howMet, $notes]);
    rn_json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'delete_partner') {
    $id = (int)($in['id'] ?? 0);
    $own = $db->prepare("SELECT agent_email FROM referral_partners WHERE id=?");
    $own->execute([$id]);
    $ownerEmail = $own->fetchColumn();
    if ($ownerEmail === false) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
    if (strtolower($ownerEmail) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    $db->prepare("DELETE FROM referral_leads WHERE partner_id=?")->execute([$id]);
    $db->prepare("DELETE FROM referral_partners WHERE id=?")->execute([$id]);
    rn_json_out(['ok' => true]);
}

// ── Referral log entries ────────────────────────────────────────────────────────
if ($action === 'save_lead') {
    $id            = (int)($in['id'] ?? 0);
    $partnerId     = (int)($in['partner_id'] ?? 0);
    $direction     = ($in['direction'] ?? 'sent') === 'received' ? 'received' : 'sent';
    $clientName    = trim($in['client_name'] ?? '');
    $clientContact = trim($in['client_contact'] ?? '');
    $status        = $in['status'] ?? 'sent';
    $notes         = trim($in['notes'] ?? '');
    if (!in_array($status, ['sent','contacted','under_contract','closed_won','closed_lost'], true)) $status = 'sent';

    $pchk = $db->prepare("SELECT agent_email FROM referral_partners WHERE id=?");
    $pchk->execute([$partnerId]);
    $ownerEmail = $pchk->fetchColumn();
    if ($ownerEmail === false) rn_json_out(['ok' => false, 'error' => 'Partner not found'], 404);
    if (strtolower($ownerEmail) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);

    if ($id > 0) {
        $own = $db->prepare("SELECT partner_id FROM referral_leads WHERE id=?");
        $own->execute([$id]);
        if ((int)$own->fetchColumn() !== $partnerId) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
        $db->prepare(
            "UPDATE referral_leads SET direction=?, client_name=?, client_contact=?, status=?, notes=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$direction, $clientName, $clientContact, $status, $notes, $id]);
        rn_json_out(['ok' => true, 'id' => $id]);
    }

    $db->prepare(
        "INSERT INTO referral_leads (partner_id, agent_email, direction, client_name, client_contact, status, notes)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$partnerId, $myEmail, $direction, $clientName, $clientContact, $status, $notes]);
    rn_json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'delete_lead') {
    $id = (int)($in['id'] ?? 0);
    $chk = $db->prepare(
        "SELECT p.agent_email FROM referral_leads l JOIN referral_partners p ON p.id = l.partner_id WHERE l.id=?"
    );
    $chk->execute([$id]);
    $ownerEmail = $chk->fetchColumn();
    if ($ownerEmail === false) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
    if (strtolower($ownerEmail) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    $db->prepare("DELETE FROM referral_leads WHERE id=?")->execute([$id]);
    rn_json_out(['ok' => true]);
}

// ── Company-wide request board ──────────────────────────────────────────────────
if ($action === 'create_request') {
    $metroId      = (int)($in['metro_id'] ?? 0);
    $notes        = trim($in['notes'] ?? '');
    $referralType = in_array($in['referral_type'] ?? '', ['buyer','seller','other'], true) ? $in['referral_type'] : 'other';
    if ($metroId <= 0) rn_json_out(['ok' => false, 'error' => 'Market is required'], 400);
    $mchk = $db->prepare("SELECT 1 FROM referral_metros WHERE id=?");
    $mchk->execute([$metroId]);
    if (!$mchk->fetchColumn()) rn_json_out(['ok' => false, 'error' => 'Unknown market'], 400);

    $db->prepare("INSERT INTO referral_requests (agent_email, metro_id, notes, referral_type) VALUES (?,?,?,?)")
       ->execute([$myEmail, $metroId, $notes, $referralType]);
    $requestId = (int)$db->lastInsertId();

    $metroLabel = referral_metro_label($db, $metroId);
    $typeLabel  = $referralType === 'other' ? 'referral' : "{$referralType} referral";
    $postText   = "{$myName} has a {$typeLabel} in {$metroLabel}. Please share if you know a great agent in that area.";

    $title = "Referral Partner Needed: {$metroLabel}";
    $body  = '<p>' . htmlspecialchars($postText, ENT_QUOTES) . '</p>'
           . ($notes !== '' ? '<p>' . nl2br(htmlspecialchars($notes, ENT_QUOTES)) . '</p>' : '')
           . '<p><a href="referral_network.php?tab=requests">Respond on the Referral Network board &rarr;</a></p>';
    $db->prepare(
        "INSERT INTO announcements (title, body, author, audience, pinned, expires_at)
         VALUES (?, ?, ?, 'all', 0, datetime('now', '+48 hours'))"
    )->execute([$title, $body, $myEmail]);
    $annId = (int)$db->lastInsertId();
    $db->prepare("UPDATE referral_requests SET announcement_id=? WHERE id=?")->execute([$annId, $requestId]);

    rn_json_out(['ok' => true, 'id' => $requestId]);
}

if ($action === 'close_request') {
    $id = (int)($in['id'] ?? 0);
    $own = $db->prepare("SELECT agent_email, announcement_id FROM referral_requests WHERE id=?");
    $own->execute([$id]);
    $row = $own->fetch(PDO::FETCH_ASSOC);
    if (!$row) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
    if (strtolower($row['agent_email']) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    $db->prepare("UPDATE referral_requests SET status='closed', closed_at=datetime('now') WHERE id=?")->execute([$id]);
    if (!empty($row['announcement_id'])) {
        $db->prepare("UPDATE announcements SET expires_at=datetime('now') WHERE id=?")->execute([$row['announcement_id']]);
    }
    rn_json_out(['ok' => true]);
}

if ($action === 'respond_request') {
    $requestId = (int)($in['request_id'] ?? 0);
    $message   = trim($in['message'] ?? '');
    $partnerId = (int)($in['partner_id'] ?? 0);
    if ($message === '' && $partnerId <= 0) rn_json_out(['ok' => false, 'error' => 'Add a note or share a partner'], 400);

    $rq = $db->prepare(
        "SELECT r.agent_email, r.status, m.metro_name, m.state_code
         FROM referral_requests r JOIN referral_metros m ON m.id = r.metro_id
         WHERE r.id=?"
    );
    $rq->execute([$requestId]);
    $req = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$req) rn_json_out(['ok' => false, 'error' => 'Not found'], 404);
    if ($req['status'] !== 'open') rn_json_out(['ok' => false, 'error' => 'This request is already closed'], 400);

    // Sharing a partner snapshots its current contact info onto the response
    // row so it survives even if the partner is later edited/deleted.
    $shared = ['name' => '', 'company' => '', 'phone' => '', 'email' => '', 'specialty' => ''];
    if ($partnerId > 0) {
        $pchk = $db->prepare("SELECT agent_email, name, company, phone, email, specialty FROM referral_partners WHERE id=?");
        $pchk->execute([$partnerId]);
        $partner = $pchk->fetch(PDO::FETCH_ASSOC);
        if (!$partner) rn_json_out(['ok' => false, 'error' => 'Partner not found'], 404);
        if (strtolower($partner['agent_email']) !== $myEmail) rn_json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        $shared = ['name' => $partner['name'], 'company' => $partner['company'], 'phone' => $partner['phone'], 'email' => $partner['email'], 'specialty' => $partner['specialty']];
    }

    $db->prepare(
        "INSERT INTO referral_request_responses
            (request_id, responder_email, message, shared_partner_name, shared_partner_company, shared_partner_phone, shared_partner_email, shared_partner_specialty)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$requestId, $myEmail, $message, $shared['name'], $shared['company'], $shared['phone'], $shared['email'], $shared['specialty']]);

    $metroLabel = $req['metro_name'] . ', ' . $req['state_code'];
    notify_referral_request_response($req['agent_email'], $metroLabel, $myName, $myEmail, $message, $shared['name'] ? $shared : null);

    rn_json_out(['ok' => true]);
}

rn_json_out(['ok' => false, 'error' => 'Unknown action'], 400);
