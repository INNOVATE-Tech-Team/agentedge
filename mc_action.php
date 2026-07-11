<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');
$agent = require_login();
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

if ($action === 'save') {
    $name     = trim($in['name'] ?? '');
    $state    = strtoupper(preg_replace('/[^A-Za-z]/', '', $in['state_code'] ?? ''));
    $slug     = slugify_mc($name);
    $ord      = (int)($in['sort_ord'] ?? 0);
    $editSlug = trim($in['edit_slug'] ?? '');

    if (!$name || !$slug) { echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }

    try {
        if ($editSlug && $editSlug !== $slug) {
            $db->prepare("DELETE FROM market_centers WHERE slug=?")->execute([$editSlug]);
        }
        $db->prepare(
            "INSERT INTO market_centers (slug, name, state_code, sort_ord, enabled)
             VALUES (?, ?, ?, ?, 1)
             ON CONFLICT(slug) DO UPDATE SET
               name=excluded.name, state_code=excluded.state_code, sort_ord=excluded.sort_ord"
        )->execute([$slug, $name, $state, $ord]);
        echo json_encode(['ok'=>true, 'slug'=>$slug, 'name'=>$name, 'state_code'=>$state, 'sort_ord'=>$ord]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $slug = trim($in['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug required']); exit; }
    $db->prepare("DELETE FROM market_centers WHERE slug=?")->execute([$slug]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'toggle') {
    $slug = trim($in['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug required']); exit; }
    $db->prepare("UPDATE market_centers SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE slug=?")->execute([$slug]);
    $stmt = $db->prepare("SELECT enabled FROM market_centers WHERE slug=?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'enabled'=>(int)($row['enabled'] ?? 0)]);
    exit;
}

if ($action === 'import') {
    $c      = cfg();
    $base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token  = $c['crm_token'] ?? '';
    $url    = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx    = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw    = @file_get_contents($url, false, $ctx);
    $roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

    $seen  = [];
    $added = 0;
    $ins   = $db->prepare(
        "INSERT OR IGNORE INTO market_centers (slug, name, state_code, sort_ord, enabled)
         VALUES (?, ?, ?, 0, 1)"
    );
    foreach ($roster as $a) {
        $mc = $a['marketCenter'] ?? '';
        if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
        if (!$mc) continue;
        $slug = slugify_mc($mc);
        if (isset($seen[$slug])) continue;
        $seen[$slug] = true;
        $state = '';
        if (preg_match('/^([A-Z]{2})\s*[-–]/', $mc, $m)) $state = $m[1];
        $ins->execute([$slug, $mc, $state]);
        if ($ins->rowCount() > 0) $added++;
    }
    echo json_encode(['ok'=>true, 'added'=>$added]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
