<?php
// Offboarding queue API — all POST/GET actions return JSON.
// Requires login + is_admin().

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../offboard_tools.php';

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
                (SELECT COUNT(*) FROM offboard_steps WHERE queue_id=q.id AND status='done') as done_count,
                (SELECT COUNT(*) FROM offboard_steps WHERE queue_id=q.id) as total_count
             FROM offboard_queue q ORDER BY q.added_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare(
            "SELECT q.*,
                (SELECT COUNT(*) FROM offboard_steps WHERE queue_id=q.id AND status='done') as done_count,
                (SELECT COUNT(*) FROM offboard_steps WHERE queue_id=q.id) as total_count
             FROM offboard_queue q WHERE q.status=? ORDER BY q.added_at DESC"
        );
        $st->execute([$filter]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($rows) {
        $ids          = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare(
            "SELECT * FROM offboard_steps WHERE queue_id IN ({$placeholders}) ORDER BY id"
        );
        $st2->execute($ids);
        $steps = $st2->fetchAll(PDO::FETCH_ASSOC);

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

    $now    = date('Y-m-d H:i:s');
    $reason = trim($body['reason'] ?? 'voluntary');
    if (!in_array($reason, ['voluntary','termination','transfer','other'], true)) {
        $reason = 'voluntary';
    }

    $ins = $pdo->prepare(
        "INSERT INTO offboard_queue
            (agent_email, agent_name, market_center, last_day, reason, reason_notes, book_of_biz_to, added_by, added_at)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ins->execute([
        $email,
        $name,
        trim($body['market_center']  ?? ''),
        trim($body['last_day']       ?? ''),
        $reason,
        trim($body['reason_notes']   ?? ''),
        trim($body['book_of_biz_to'] ?? ''),
        $agent['email'],
        $now,
    ]);
    $queueId = (int)$pdo->lastInsertId();

    // Insert a step row for each tool
    $stepIns = $pdo->prepare(
        "INSERT OR IGNORE INTO offboard_steps
            (queue_id, tool_key, tool_label, is_auto, status)
         VALUES (?,?,?,?,?)"
    );
    foreach (offboard_tools() as $t) {
        $stepIns->execute([
            $queueId,
            $t['key'],
            $t['label'],
            $t['is_auto'] ? 1 : 0,
            'pending',
        ]);
    }

    // Queue notification emails then flush response
    try {
        require_once __DIR__ . '/../lib/notifications.php';
        notify_offboard_added(
            $name,
            $email,
            trim($body['market_center']  ?? ''),
            trim($body['last_day']       ?? ''),
            $reason,
            trim($body['reason_notes']   ?? ''),
            $agent['email']
        );
        notify_step_assignees_on_create('offboard', $name, $email, offboard_tools());
        maybe_notify_next_actionable_step($pdo, 'offboard', $queueId);
    } catch (\Throwable $e) {}

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'id'=>$queueId]);
    try {
        require_once __DIR__ . '/../lib/notifications.php';
        dispatch_notification_queue();
    } catch (\Throwable $e) {}
    exit;
}

// ── POST: mark_done ───────────────────────────────────────────────────────────
if ($action === 'mark_done') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $toolKey = trim($body['tool_key'] ?? '');
    $status  = trim($body['status']   ?? 'done');
    if (!in_array($status, ['done','pending','skipped'], true)) {
        json_out(['ok'=>false,'error'=>'Invalid status']);
    }

    try { require_once __DIR__ . '/../lib/notifications.php'; } catch (\Throwable $e) {}

    if ($status === 'done') {
        try { complete_offboard_step($pdo, $queueId, $toolKey, $agent['email']); } catch (\Throwable $e) {}
    } else {
        $upd = $pdo->prepare(
            "UPDATE offboard_steps SET status=?, done_by=NULL, done_at=NULL
             WHERE queue_id=? AND tool_key=?"
        );
        $upd->execute([$status, $queueId, $toolKey]);
        if ($status === 'skipped') {
            try { maybe_notify_next_actionable_step($pdo, 'offboard', $queueId); } catch (\Throwable $e) {}
        }
    }

    json_out(['ok'=>true]);
}

// ── POST: send_exit_interview ─────────────────────────────────────────────────
if ($action === 'send_exit_interview') {
    $queueId = (int)($body['queue_id'] ?? 0);

    $q = $pdo->prepare("SELECT * FROM offboard_queue WHERE id=?");
    $q->execute([$queueId]);
    $entry = $q->fetch(PDO::FETCH_ASSOC);
    if (!$entry) json_out(['ok'=>false,'error'=>'Queue entry not found']);

    try {
        require_once __DIR__ . '/../lib/notifications.php';
        notify_exit_interview_sent($entry['agent_name'], $entry['agent_email']);
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

// ── POST: provision (auto-deprovision) ────────────────────────────────────────
if ($action === 'provision') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $toolKey = trim($body['tool_key'] ?? '');

    $q = $pdo->prepare("SELECT * FROM offboard_queue WHERE id=?");
    $q->execute([$queueId]);
    $entry = $q->fetch(PDO::FETCH_ASSOC);
    if (!$entry) json_out(['ok'=>false,'error'=>'Queue entry not found']);

    $now    = date('Y-m-d H:i:s');
    $result = ['ok'=>false,'error'=>'not an auto tool'];

    if ($toolKey === 'fub') {
        require_once __DIR__ . '/../lib/fub.php';
        $result = fub_deactivate_user($entry['agent_email']);
    } elseif ($toolKey === 'constellation1') {
        require_once __DIR__ . '/../lib/c1.php';
        // c1_deactivate_user() is a stub — mark as manual until implemented
        $result = ['ok'=>false,'error'=>'Constellation1 deactivation not yet implemented — remove manually'];
    }

    if ($result['ok']) {
        $upd = $pdo->prepare(
            "UPDATE offboard_steps SET status='done', done_by=?, done_at=?, error_msg=NULL
             WHERE queue_id=? AND tool_key=?"
        );
        $upd->execute([$agent['email'], $now, $queueId, $toolKey]);
    } else {
        $errMsg = $result['error'] ?? 'Unknown error';
        $upd = $pdo->prepare(
            "UPDATE offboard_steps SET status='failed', done_by=NULL, done_at=NULL, error_msg=?
             WHERE queue_id=? AND tool_key=?"
        );
        $upd->execute([$errMsg, $queueId, $toolKey]);
        json_out(['ok'=>false,'error'=>$errMsg]);
    }

    try {
        require_once __DIR__ . '/../lib/notifications.php';
        maybe_notify_next_actionable_step($pdo, 'offboard', $queueId);
    } catch (\Throwable $e) {}

    json_out(['ok'=>true] + (isset($result['note']) ? ['note'=>$result['note']] : []));
}

// ── POST: complete_offboarding ────────────────────────────────────────────────
if ($action === 'complete_offboarding') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE offboard_queue SET status='completed' WHERE id=?");
    $upd->execute([$queueId]);
    json_out(['ok'=>true]);
}

// ── POST: cancel_offboarding ──────────────────────────────────────────────────
if ($action === 'cancel_offboarding') {
    $queueId = (int)($body['queue_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE offboard_queue SET status='cancelled' WHERE id=?");
    $upd->execute([$queueId]);
    json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'Unknown action'], 400);
