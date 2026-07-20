<?php
// Onboarding queue API — all POST/GET actions return JSON.
// Requires login + is_admin().

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../onboard_tools.php';
require_once __DIR__ . '/../lib/onboarding.php';
require_once __DIR__ . '/../lib/roster.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$agent = require_login();
if (!is_admin()) json_out(['ok'=>false,'error'=>'Admin access required'], 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo    = local_db();

// ── GET: list_queue ───────────────────────────────────────────────────────────
if ($action === 'list_queue') {
    $filter = $_GET['filter'] ?? 'active';

    if ($filter === 'all') {
        $rows = $pdo->query(
            "SELECT q.*,
                (SELECT COUNT(*) FROM onboard_steps WHERE queue_id=q.id AND status='done') as done_count,
                (SELECT COUNT(*) FROM onboard_steps WHERE queue_id=q.id) as total_count
             FROM onboard_queue q ORDER BY q.added_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare(
            "SELECT q.*,
                (SELECT COUNT(*) FROM onboard_steps WHERE queue_id=q.id AND status='done') as done_count,
                (SELECT COUNT(*) FROM onboard_steps WHERE queue_id=q.id) as total_count
             FROM onboard_queue q WHERE q.status=? ORDER BY q.added_at DESC"
        );
        $st->execute([$filter]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Attach steps for each entry
    if ($rows) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare(
            "SELECT * FROM onboard_steps WHERE queue_id IN ({$placeholders}) ORDER BY id"
        );
        $st2->execute($ids);
        $steps = $st2->fetchAll(PDO::FETCH_ASSOC);

        // Group steps by queue_id
        $byQueue = [];
        foreach ($steps as $s) {
            $byQueue[$s['queue_id']][] = $s;
        }
        foreach ($rows as &$row) {
            $row['steps'] = $byQueue[$row['id']] ?? [];
        }
        unset($row);
    }

    json_out(['ok'=>true,'queue'=>$rows]);
}

// ── GET: search_crm ───────────────────────────────────────────────────────────
if ($action === 'search_crm') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_out(['ok'=>true,'results'=>[]]);

    $c       = cfg();
    $base    = rtrim($c['crm_base'] ?? '', '/');
    $token   = $c['crm_token'] ?? '';
    $matches = [];

    // External CRM (bold360), only when crm_base is configured.
    if ($base !== '') {
        $url = $base . '/public/retention-roster?token=' . urlencode($token);
        $ctx = stream_context_create(['http'=>['timeout'=>10,'header'=>"Accept: application/json\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data   = $raw !== false ? json_decode($raw, true) : null;
        $agents = $data['agents'] ?? $data ?? [];

        if (is_array($agents)) {
            $ql = strtolower($q);
            foreach ($agents as $a) {
                $name  = $a['name'] ?? trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? ''));
                $email = $a['email'] ?? '';
                if (stripos($name, $ql) !== false || stripos($email, $ql) !== false) {
                    $matches[] = [
                        'name'         => trim($name),
                        'email'        => $email,
                        'marketCenter' => $a['marketCenter'] ?? $a['market_center'] ?? '',
                        'phone'        => $a['phone'] ?? $a['phoneNumber'] ?? '',
                    ];
                }
                if (count($matches) >= 10) break;
            }
        }
    }

    // Local Perfex fallback — used when crm_base is empty (this deployment's
    // normal mode; see config.php) or the external CRM was unreachable.
    if (!$matches) {
        $like = '%' . $q . '%';
        $rows = db_query(
            "SELECT staffid, email, firstname, lastname, phonenumber
             FROM tblstaff
             WHERE active=1 AND (firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)
             LIMIT 10",
            [$like, $like, $like]
        );

        $mcByName = [];
        try {
            $mcRows = local_db()->query(
                "SELECT agent_name, state_code, market_center FROM innovate_roster WHERE active=1 AND market_center != ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mcRows as $r) {
                $key = strtolower(trim($r['agent_name']));
                $mc  = trim($r['market_center']);
                $st  = trim($r['state_code']);
                $mcByName[$key] = mc_label($mc, $st);
            }
        } catch (\Throwable $e) {}

        foreach ($rows as $s) {
            $name = trim($s['firstname'] . ' ' . $s['lastname']);
            $matches[] = [
                'name'         => $name,
                'email'        => $s['email'],
                'marketCenter' => $mcByName[strtolower($name)] ?? '',
                'phone'        => $s['phonenumber'] ?? '',
            ];
        }
    }

    json_out(['ok'=>true,'results'=>$matches]);
}

// ── All remaining actions require POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'POST required'], 405);
}

$body = [];
$raw  = file_get_contents('php://input');
if ($raw) $body = json_decode($raw, true) ?? [];
// Also accept form-encoded POST
foreach ($_POST as $k => $v) {
    if (!isset($body[$k])) $body[$k] = $v;
}

// ── POST: add_to_queue ────────────────────────────────────────────────────────
if ($action === 'add_to_queue') {
    $email = trim($body['agent_email'] ?? '');
    $name  = trim($body['agent_name']  ?? '');
    if ($email === '' || $name === '') {
        json_out(['ok'=>false,'error'=>'agent_email and agent_name are required']);
    }

    $result = queue_onboarding_agent(
        $pdo,
        $email,
        $name,
        $body['market_center'] ?? '',
        $body['state_code']    ?? '',
        null,
        $agent['email'],
        $body['start_date'] ?? '',
        $body['sponsor']    ?? '',
        $body['role']       ?? 'agent',
        $body['notes']      ?? ''
    );
    $queueId = $result['id'];

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'id'=>$queueId]);
    if ($result['wasNew']) {
        try {
            require_once __DIR__ . '/../lib/notifications.php';
            dispatch_notification_queue();
        } catch (\Throwable $e) {}
    }
    exit;
}

// ── POST: set_state ───────────────────────────────────────────────────────────
// An agent can be licensed in more than one state, so state_code stores a
// comma-separated list (e.g. "SC,NC") — accepts state_codes as an array, or a
// single state_code string for backward compatibility.
if ($action === 'set_state') {
    $queueId = (int)($body['queue_id'] ?? 0);
    if (!$queueId) json_out(['ok'=>false,'error'=>'queue_id is required']);

    $raw = $body['state_codes'] ?? $body['state_code'] ?? [];
    if (!is_array($raw)) $raw = [$raw];

    $states = [];
    foreach ($raw as $s) {
        $s = strtoupper(trim((string)$s));
        if ($s === '') continue;
        if (!in_array($s, ONBOARD_VALID_STATES, true)) {
            json_out(['ok'=>false,'error'=>"\"$s\" is not a valid state."]);
        }
        if (!in_array($s, $states, true)) $states[] = $s;
    }

    $pdo->prepare("UPDATE onboard_queue SET state_code = ? WHERE id = ?")->execute([implode(',', $states), $queueId]);
    json_out(['ok'=>true]);
}

// ── POST: set_market_center ───────────────────────────────────────────────────
// Lets an admin fix a queue entry's Market Center after the fact — needed
// because api/onboard_push.php (Advantage CRM) can send a value that doesn't
// match the canonical market_centers list, which normalize_market_center()
// then leaves blank rather than passing through untouched.
if ($action === 'set_market_center') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $mc      = normalize_market_center($pdo, $body['market_center'] ?? '');
    if (!$queueId || $mc === '') {
        json_out(['ok'=>false,'error'=>'queue_id and a valid market_center are required']);
    }
    $pdo->prepare("UPDATE onboard_queue SET market_center = ? WHERE id = ?")->execute([$mc, $queueId]);
    json_out(['ok'=>true]);
}

// ── POST: mark_done ───────────────────────────────────────────────────────────
if ($action === 'mark_done') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $toolKey = trim($body['tool_key'] ?? '');
    $status  = trim($body['status']   ?? 'done');
    if (!in_array($status, ['done','pending','skipped'], true)) {
        json_out(['ok'=>false,'error'=>'Invalid status']);
    }

    $now    = date('Y-m-d H:i:s');
    $doneBy = $status === 'done' ? $agent['email'] : null;
    $doneAt = $status === 'done' ? $now             : null;

    $upd = $pdo->prepare(
        "UPDATE onboard_steps SET status=?, done_by=?, done_at=?
         WHERE queue_id=? AND tool_key=?"
    );
    $upd->execute([$status, $doneBy, $doneAt, $queueId, $toolKey]);

    if (in_array($status, ['done','skipped'], true)) {
        try {
            require_once __DIR__ . '/../lib/notifications.php';
            maybe_notify_next_actionable_step($pdo, 'onboard', $queueId);
        } catch (\Throwable $e) {}
    }

    json_out(['ok'=>true]);
}

// ── POST: provision ───────────────────────────────────────────────────────────
if ($action === 'provision') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $toolKey = trim($body['tool_key'] ?? '');

    // Load queue entry
    $q = $pdo->prepare("SELECT * FROM onboard_queue WHERE id=?");
    $q->execute([$queueId]);
    $entry = $q->fetch(PDO::FETCH_ASSOC);
    if (!$entry) json_out(['ok'=>false,'error'=>'Queue entry not found']);

    $now    = date('Y-m-d H:i:s');
    $result = ['ok'=>false,'error'=>'not an auto tool'];

    if ($toolKey === 'fub') {
        require_once __DIR__ . '/../lib/fub.php';
        $result = fub_create_user($entry['agent_name'], $entry['agent_email']);
    } elseif ($toolKey === 'constellation1') {
        require_once __DIR__ . '/../lib/c1.php';
        $parts = explode(' ', $entry['agent_name'], 2);
        $first = $parts[0];
        $last  = $parts[1] ?? '';
        $result = c1_create_user($first, $last, $entry['agent_email']);
    } elseif ($toolKey === 'doc_signing') {
        require_once __DIR__ . '/../lib/pandadoc.php';
        $existingDocId = $pdo->prepare("SELECT pandadoc_document_id FROM onboard_steps WHERE queue_id=? AND tool_key=?");
        $existingDocId->execute([$queueId, $toolKey]);
        $result = pandadoc_send_document(
            $entry['agent_name'], $entry['agent_email'], $existingDocId->fetchColumn() ?: null,
            function (string $docId) use ($pdo, $queueId, $toolKey) {
                // Persist immediately, before the slower poll/send steps below —
                // if this request gets interrupted partway, the document id
                // still needs to be recoverable (the webhook matches on it).
                $pdo->prepare("UPDATE onboard_steps SET pandadoc_document_id=? WHERE queue_id=? AND tool_key=?")
                    ->execute([$docId, $queueId, $toolKey]);
            },
            // Only one template can be picked — an agent licensed in several
            // states gets the doc for the first one listed.
            explode(',', $entry['state_code'] ?? '')[0] ?: null
        );
    }

    // A doc_signing send only puts the document in front of the agent —
    // completion ('done') is set later by the webhook once they actually sign.
    $successStatus = ($toolKey === 'doc_signing') ? 'sent' : 'done';

    // Update step status
    if ($result['ok']) {
        $upd = $pdo->prepare(
            "UPDATE onboard_steps SET status=?, done_by=?, done_at=?, error_msg=NULL,
                    pandadoc_document_id=COALESCE(?, pandadoc_document_id)
             WHERE queue_id=? AND tool_key=?"
        );
        $upd->execute([$successStatus, $agent['email'], $now, $result['document_id'] ?? null, $queueId, $toolKey]);
    } else {
        $errMsg = $result['error'] ?? 'Unknown error';
        $upd = $pdo->prepare(
            "UPDATE onboard_steps SET status='failed', done_by=NULL, done_at=NULL, error_msg=?,
                    pandadoc_document_id=COALESCE(?, pandadoc_document_id)
             WHERE queue_id=? AND tool_key=?"
        );
        $upd->execute([$errMsg, $result['document_id'] ?? null, $queueId, $toolKey]);
        json_out(['ok'=>false,'error'=>$errMsg]);
    }

    try {
        require_once __DIR__ . '/../lib/notifications.php';
        maybe_notify_next_actionable_step($pdo, 'onboard', $queueId);
    } catch (\Throwable $e) {}

    json_out(['ok'=>true] + (isset($result['note']) ? ['note'=>$result['note']] : []));
}

// ── POST: complete_onboarding ─────────────────────────────────────────────────
if ($action === 'complete_onboarding') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM onboard_queue WHERE id = ?");
    $st->execute([$queueId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_out(['ok'=>false,'error'=>'Queue entry not found'], 404);

    // state_code is a comma-separated list — an agent can be licensed in more
    // than one state. Each valid state gets its own innovate_roster row (same
    // Market Center on all of them), tied together by canonical_agent_id.
    $states = array_values(array_unique(array_filter(
        array_map(fn($s) => strtoupper(trim($s)), explode(',', $row['state_code'] ?? ''))
    )));
    if (!$states) {
        json_out(['ok'=>false,'error'=>'Set at least one valid license state for this agent before completing onboarding.']);
    }
    foreach ($states as $s) {
        if (!in_array($s, ROSTER_VALID_STATES, true)) {
            json_out(['ok'=>false,'error'=>"\"$s\" is not a valid license state."]);
        }
    }
    // Same treatment as state above — market_center only ever reaches this
    // table already normalized against the canonical list (see
    // normalize_market_center()), so a blank value here means it was never
    // set or didn't match a real Market Center and needs a human to fix it.
    $mc = trim($row['market_center'] ?? '');
    if ($mc === '') {
        json_out(['ok'=>false,'error'=>'Set a Market Center for this agent before completing onboarding.']);
    }

    foreach ($states as $s) {
        add_or_reactivate_roster_agent(
            $pdo,
            $row['agent_name'],
            $s,
            $mc,
            '',
            $row['canonical_agent_id'] ?? null,
            $agent['email']
        );
    }

    $upd = $pdo->prepare("UPDATE onboard_queue SET status='completed' WHERE id=?");
    $upd->execute([$queueId]);

    try {
        require_once __DIR__ . '/../lib/notifications.php';
        notify_onboard_completed($row['agent_name'], $row['agent_email']);
        notify_bic_ml_onboard_complete($row['agent_name'], $row['agent_email'], $row['market_center'] ?? '');

        // Step 11 (Coach/LAUNCH assignment) is new-agents-only — determined by
        // whether the intake form shows a prior brokerage; blank means new.
        $intakeSt = $pdo->prepare("SELECT prior_occupation, prior_affiliation FROM agent_intake WHERE email = ?");
        $intakeSt->execute([$row['agent_email']]);
        $intake = $intakeSt->fetch(PDO::FETCH_ASSOC);
        $isNewAgent = $intake && trim($intake['prior_occupation'] ?? '') === '' && trim($intake['prior_affiliation'] ?? '') === '';
        if ($isNewAgent) {
            notify_coach_assignment_needed($row['agent_name'], $row['agent_email']);
        }

        // Step 13 — schedule the 10-day post-completion check-in text.
        $pdo->prepare(
            "INSERT INTO scheduled_tasks (task_type, payload_json, fire_at) VALUES (?,?,datetime('now','+10 days'))"
        )->execute(['onboard_followup_text', json_encode(['agent_name' => $row['agent_name'], 'agent_email' => $row['agent_email']])]);
    } catch (\Throwable $e) {}

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]);
    try {
        require_once __DIR__ . '/../lib/notifications.php';
        dispatch_notification_queue();
    } catch (\Throwable $e) {}
    exit;
}

// ── POST: cancel_onboarding ───────────────────────────────────────────────────
if ($action === 'cancel_onboarding') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE onboard_queue SET status='cancelled' WHERE id=?");
    $upd->execute([$queueId]);
    json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'Unknown action'], 400);
