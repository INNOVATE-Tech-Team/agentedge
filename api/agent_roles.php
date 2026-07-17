<?php
// Single-agent role/placement read+write — Agent Permission tab on
// agent_profile.php. Extracted from admin_roles.php's form+upsert logic,
// parameterized by one email instead of that page's own search-to-assign flow.
// GET  ?email=...  → super_admin only: role/mc_slugs/own_mc_slug/bic_email
//                      for this agent, plus mc_opts/bic_list dropdown data.
// POST             → super_admin only: upsert those fields, or {action:'remove'}.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
// Matches admin_roles.php's own gate exactly — role assignment is not opened
// up to plain staff/admin via this tab.
if (!is_super_admin()) { http_response_code(403); echo json_encode(['error' => 'super admin only']); exit; }

$pdo = local_db();

function agent_roles_mc_opts(): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    $url   = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx   = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw   = @file_get_contents($url, false, $ctx);
    $roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

    $rosterByEmail = [];
    $mc_opts       = [];
    foreach ($roster as $a) {
        $mc = $a['marketCenter'] ?? '';
        if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
        $email = strtolower(trim($a['email'] ?? ''));
        if (!$email) continue;
        $slug = slugify_mc($mc);
        if ($mc && $slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $mc;
        $rosterByEmail[$email] = ['email' => $email, 'name' => $a['fullName'] ?? $email, 'mc' => $mc];
    }
    foreach (local_db()->query("SELECT slug, name FROM market_centers WHERE enabled=1 ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC) as $mc) {
        if (!isset($mc_opts[$mc['slug']])) $mc_opts[$mc['slug']] = $mc['name'];
    }
    ksort($mc_opts);
    return [$mc_opts, $rosterByEmail];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }

    [$mc_opts, $rosterByEmail] = agent_roles_mc_opts();

    $bicEmails = local_db()->query("SELECT email FROM agent_roles WHERE role='bic' ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
    $bicList = array_map(function ($be) use ($rosterByEmail) {
        return ['email' => $be, 'name' => $rosterByEmail[$be]['name'] ?? $be];
    }, $bicEmails);

    $st = $pdo->prepare("SELECT role, mc_slugs, own_mc_slug, bic_email FROM agent_roles WHERE email=?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $role     = canonical_role($row['role'] ?? 'agent');
    $mc_slugs = $row ? (json_decode($row['mc_slugs'] ?? '[]', true) ?: []) : [];

    echo json_encode([
        'ok'          => true,
        'role'        => $role,
        'mc_slugs'    => $mc_slugs,
        'own_mc_slug' => $row['own_mc_slug'] ?? '',
        'bic_email'   => $row['bic_email']   ?? '',
        'mc_opts'     => $mc_opts,
        'bic_list'    => $bicList,
        'role_labels' => ROLE_LABELS,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($body['email'] ?? ''));
if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }

if (($body['action'] ?? '') === 'remove') {
    $pdo->prepare("DELETE FROM agent_roles WHERE email=?")->execute([$email]);
    echo json_encode(['ok' => true, 'removed' => true]);
    exit;
}

$role = preg_replace('/[^a-z_]/', '', $body['role'] ?? 'agent');
if (!isset(ROLE_LABELS[$role])) $role = 'agent';

$mcs = [];
if (in_array($role, ['bic', 'mc_leader'], true) && !empty($body['mc_slugs'])) {
    foreach ((array)$body['mc_slugs'] as $s) {
        $s = preg_replace('/[^a-z0-9\-]/', '', $s);
        if ($s) $mcs[] = $s;
    }
}

$ownMcSlug = preg_replace('/[^a-z0-9\-]/', '', $body['own_mc_slug'] ?? '');
$bicEmail  = strtolower(trim($body['bic_email'] ?? ''));
// Only agents get a bic_email assignment; leaders/admins don't need one.
if (in_array($role, ['super_admin', 'staff', 'mc_leader', 'bic', 'recruiter'], true)) {
    $bicEmail = '';
}

$json = json_encode(array_values(array_unique($mcs)));
$pdo->prepare(
    "INSERT INTO agent_roles (email, role, mc_slugs, own_mc_slug, bic_email, updated_by, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
       role=excluded.role, mc_slugs=excluded.mc_slugs,
       own_mc_slug=excluded.own_mc_slug, bic_email=excluded.bic_email,
       updated_by=excluded.updated_by, updated_at=excluded.updated_at"
)->execute([$email, $role, $json, $ownMcSlug, $bicEmail, strtolower($agent['email'])]);

if ($role === 'agent' && $ownMcSlug === '' && $bicEmail === '') {
    $pdo->prepare("DELETE FROM agent_roles WHERE email=?")->execute([$email]);
}

echo json_encode(['ok' => true, 'role' => $role, 'mc_slugs' => json_decode($json, true), 'own_mc_slug' => $ownMcSlug, 'bic_email' => $bicEmail]);
