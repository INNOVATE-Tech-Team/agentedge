<?php
// Proxy for "My AI Agents" (ai_agents.php) -> coastline-server
// /public/agentedge/ai/*. One file, routed by ?op=, so the crm_token and the
// signed-email assertion never reach the browser. Every call acts as the
// signed-in agent only (crm_signed_email), and the CRM scopes everything to
// that agent's own deployed agents. Added 2026-09-30.
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/crm_email_assertion.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_use_buyback()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$op   = $_GET['op'] ?? '';
$tpl  = preg_replace('/[^a-z0-9_]/', '', $_GET['template'] ?? '');
$aid  = preg_replace('/[^a-f0-9-]/', '', strtolower($_GET['action_id'] ?? ''));
$body = file_get_contents('php://input') ?: '';
$method = $_SERVER['REQUEST_METHOD'];

// op => [HTTP method, CRM path, extra query params]
$routes = [
    'templates' => ['GET',   '/public/agentedge/ai/templates', []],
    'agents'    => ['GET',   '/public/agentedge/ai/agents', []],
    'deploy'    => ['POST',  '/public/agentedge/ai/agents', []],
    'update'    => ['PATCH', '/public/agentedge/ai/agents/' . $tpl, []],
    'run'       => ['POST',  '/public/agentedge/ai/agents/' . $tpl . '/run', []],
    'actions'   => ['GET',   '/public/agentedge/ai/actions', [
                        'status'       => preg_replace('/[^a-z_]/', '', $_GET['status'] ?? 'pending_approval'),
                        'template_key' => $tpl,
                        'limit'        => (int)($_GET['limit'] ?? 50),
                    ]],
    'approve'   => ['POST',  '/public/agentedge/ai/actions/' . $aid . '/approve', []],
    'reject'    => ['POST',  '/public/agentedge/ai/actions/' . $aid . '/reject', []],
];
if (!isset($routes[$op])) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown op']); exit; }
[$crmMethod, $path, $extra] = $routes[$op];
if (in_array($op, ['update', 'run'], true) && $tpl === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'template is required']); exit; }
if (in_array($op, ['approve', 'reject'], true) && $aid === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'action_id is required']); exit; }
if ($crmMethod !== 'GET' && $method !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }

try {
    $signed = crm_signed_email($agent['email']);
} catch (\RuntimeException $e) {
    http_response_code(503); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}
$c    = cfg();
$base = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$qs   = http_build_query(array_merge(['token' => $c['crm_token'] ?? '', 'email' => $signed], $extra));

$ch = curl_init($base . $path . '?' . $qs);
$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $crmMethod];
if ($crmMethod !== 'GET') {
    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    $opts[CURLOPT_POSTFIELDS] = $body !== '' ? $body : '{}';
}
curl_setopt_array($ch, $opts);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the CRM: ' . $err]);
    exit;
}
http_response_code($status ?: 502);
echo $resp;
