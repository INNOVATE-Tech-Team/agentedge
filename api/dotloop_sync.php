<?php
// DotLoop background sync — fetches ALL loops from all profiles into SQLite cache.
// Called by JS; streams JSON progress so the browser can show a progress bar.
// Runs incrementally: resumes from where it left off if interrupted.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/dotloop.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
$email = $agent['email'];

$connected = dotloop_is_connected($email);
if (!$connected) { echo json_encode(['ok'=>false,'error'=>'DotLoop not connected']); exit; }

$tokens = dotloop_get_tokens($email);
$allProfilesRaw = $tokens['all_profiles'] ?? null;
$profileList = $allProfilesRaw ? (json_decode($allProfilesRaw, true) ?? []) : [['id'=>$tokens['profile_id'],'name'=>'','type'=>'AGENT']];

$ldb    = local_db();
$action = $_GET['action'] ?? 'status';

// ── action=status ─────────────────────────────────────────────────────────────
if ($action === 'status') {
    $s = $ldb->prepare("SELECT status, total, synced, finished_at FROM dotloop_sync_state WHERE agent_email = ?");
    $s->execute([$email]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $cacheCount = (int)$ldb->query("SELECT COUNT(*) FROM dotloop_loop_cache WHERE agent_email = " . $ldb->quote($email))->fetchColumn();
    echo json_encode(['ok'=>true, 'state'=>$row ?: ['status'=>'idle','total'=>0,'synced'=>0], 'cached'=>$cacheCount]);
    exit;
}

// ── action=clear ──────────────────────────────────────────────────────────────
if ($action === 'clear') {
    $ldb->prepare("DELETE FROM dotloop_loop_cache WHERE agent_email = ?")->execute([$email]);
    $ldb->prepare("DELETE FROM dotloop_sync_state WHERE agent_email = ?")->execute([$email]);
    echo json_encode(['ok'=>true,'message'=>'Cache cleared']);
    exit;
}

// ── action=run — fetch one batch of pages and store ──────────────────────────
if ($action === 'run') {
    // Load or init sync state
    $ss = $ldb->prepare("SELECT * FROM dotloop_sync_state WHERE agent_email = ?");
    $ss->execute([$email]);
    $state = $ss->fetch(PDO::FETCH_ASSOC);

    if (!$state || $state['status'] === 'idle' || $state['status'] === 'done') {
        // Fresh start — calculate totals first
        $totalLoops = 0;
        $profileMeta = [];
        foreach ($profileList as $prof) {
            $pid = (string)($prof['id'] ?? '');
            if ($pid === '') continue;
            $r = dotloop_api($email, 'GET', "/profile/{$pid}/loop?pg=1");
            if (!$r['ok']) continue;
            $t = (int)($r['data']['meta']['total'] ?? 0);
            $pages = $t > 0 ? (int)ceil($t / 20) : 0;
            $totalLoops += $t;
            $profileMeta[] = ['id'=>$pid, 'name'=>$prof['name']??'', 'total'=>$t, 'pages'=>$pages, 'done_pages'=>0];
        }
        // Store profile meta in sync state as JSON in 'status' field
        $stateJson = json_encode(['profiles'=>$profileMeta]);
        $ldb->prepare("INSERT OR REPLACE INTO dotloop_sync_state (agent_email,status,total,synced,started_at,finished_at) VALUES (?,?,?,?,datetime('now'),NULL)")
            ->execute([$email, $stateJson, $totalLoops, 0]);
        $state = ['status'=>$stateJson,'total'=>$totalLoops,'synced'=>0];
    }

    $stateData = json_decode($state['status'], true);
    if (!$stateData || !isset($stateData['profiles'])) {
        // Already done or error state
        echo json_encode(['ok'=>true,'done'=>true,'cached'=>(int)$ldb->query("SELECT COUNT(*) FROM dotloop_loop_cache WHERE agent_email = " . $ldb->quote($email))->fetchColumn()]);
        exit;
    }

    $profiles  = $stateData['profiles'];
    $synced    = (int)$state['synced'];
    $total     = (int)$state['total'];
    $batchSize = 5; // pages per run call
    $pagesThisRun = 0;
    $done = true;

    $insertStmt = $ldb->prepare(
        "INSERT OR REPLACE INTO dotloop_loop_cache (agent_email,loop_id,profile_id,profile_name,loop_json,loop_updated)
         VALUES (?,?,?,?,?,?)"
    );

    foreach ($profiles as &$prof) {
        if ($prof['done_pages'] >= $prof['pages']) continue;
        $done = false;
        $pid      = $prof['id'];
        $profName = $prof['name'];

        for ($i = 0; $i < $batchSize && $prof['done_pages'] < $prof['pages']; $i++) {
            $fetchPage = $prof['done_pages'] + 1;
            $r = dotloop_api($email, 'GET', "/profile/{$pid}/loop?pg={$fetchPage}");
            if (!$r['ok']) { $prof['done_pages']++; continue; }
            $loops = $r['data']['data'] ?? [];
            $ldb->beginTransaction();
            foreach ($loops as $lp) {
                $lid = (string)($lp['id'] ?? '');
                if ($lid === '') continue;
                $insertStmt->execute([
                    $email, $lid, $pid, $profName,
                    json_encode($lp),
                    $lp['updated'] ?? ''
                ]);
                $synced++;
            }
            $ldb->commit();
            $prof['done_pages']++;
            $pagesThisRun++;
        }
        if ($pagesThisRun >= $batchSize) break; // one profile per run to avoid timeout
    }
    unset($prof);

    $isDone = true;
    foreach ($profiles as $p) { if ($p['done_pages'] < $p['pages']) { $isDone = false; break; } }

    $newStateJson = $isDone ? 'done' : json_encode(['profiles'=>$profiles]);
    $ldb->prepare("UPDATE dotloop_sync_state SET status=?, synced=?, finished_at=? WHERE agent_email=?")
        ->execute([$newStateJson, $synced, $isDone ? "datetime('now')" : null, $email]);

    echo json_encode([
        'ok'     => true,
        'done'   => $isDone,
        'synced' => $synced,
        'total'  => $total,
        'pct'    => $total > 0 ? round($synced / $total * 100) : 100,
    ]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
