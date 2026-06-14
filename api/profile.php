<?php
// My profile — load & save the logged-in agent's own record in the bold360.vip
// CRM (agent_overrides). The agent is identified server-side by their session
// email, so nobody can edit a record that isn't theirs by tampering with an id.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$myEmail = strtolower(trim($me['email'] ?? ''));

function http_json(string $method, string $url, ?array $body = null): array {
    $opts = ['http' => [
        'method'  => $method,
        'timeout' => 12,
        'header'  => "Accept: application/json\r\nContent-Type: application/json\r\n",
        'ignore_errors' => true,
    ]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body); }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    $data = $raw === false ? null : json_decode($raw, true);
    return ['code' => $code, 'data' => $data];
}

// Find my CRM record by matching my login email against the retention roster.
function find_me(string $base, string $token, string $myEmail): ?array {
    if ($token === '' || $myEmail === '') return null;
    $r = http_json('GET', $base . '/public/retention-roster?token=' . urlencode($token));
    if (!is_array($r['data'])) return null;
    foreach ($r['data'] as $a) {
        if (strtolower(trim($a['email'] ?? '')) === $myEmail) return $a;
    }
    return null;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'save' : 'load';

// ---- No token configured: editing is unavailable -------------------------
if ($token === '') {
    echo json_encode(['matched' => false, 'editable' => false,
        'reason' => 'Profile editing isn\'t configured yet (no CRM token).']);
    exit;
}

$record = find_me($base, $token, $myEmail);

if ($action === 'load') {
    if (!$record) {
        echo json_encode(['matched' => false, 'editable' => false,
            'reason' => 'We couldn\'t match your login to a roster record.',
            'profile' => ['fullName' => $me['name'] ?? '', 'email' => $me['email'] ?? '']]);
        exit;
    }
    echo json_encode([
        'matched'  => true,
        'editable' => empty($c['demo']),
        'demo'     => !empty($c['demo']),
        'profile'  => [
            'id'           => $record['id'] ?? null,
            'fullName'     => $record['name'] ?? ($record['fullName'] ?? ''),
            'email'        => $record['email'] ?? '',
            'phone'        => $record['phone'] ?? '',
            'marketCenter' => $record['marketCenter'] ?? '',
            'brokerage'    => $record['brokerage'] ?? '',
            'social'       => $record['social'] ?? new stdClass(),
        ],
    ]);
    exit;
}

// ---- Save -----------------------------------------------------------------
if (!$record || empty($record['id'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'We couldn\'t match your record, so there\'s nothing to save to.']);
    exit;
}
if (!empty($c['demo'])) {
    echo json_encode(['ok' => false, 'demo' => true,
        'error' => 'Preview mode — changes aren\'t saved. Editing goes live on the production server.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$payload = [
    'full_name'     => $in['fullName'] ?? null,
    'email'         => $in['email'] ?? null,
    'phone'         => $in['phone'] ?? null,
    'facebook_url'  => $in['facebook'] ?? null,
    'instagram_url' => $in['instagram'] ?? null,
    'linkedin_url'  => $in['linkedin'] ?? null,
    'twitter_url'   => $in['twitter'] ?? null,
    'youtube_url'   => $in['youtube'] ?? null,
    'tiktok_url'    => $in['tiktok'] ?? null,
    'website_url'   => $in['website'] ?? null,
    'blog_url'      => $in['blog'] ?? null,
];
$url = $base . '/public/agent/' . rawurlencode($record['id']) . '?token=' . urlencode($token);
$res = http_json('POST', $url, $payload);
if ($res['code'] === 200 && is_array($res['data']) && !empty($res['data']['ok'])) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'The CRM rejected the save (code ' . $res['code'] . ').']);
}
