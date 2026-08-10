<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
// Cross-agent oversight -- admin/BIC only, not every producing agent.
if (!is_admin() && !is_bic()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$agentType = isset($_GET['agent_type']) ? trim($_GET['agent_type']) : '';
$status    = isset($_GET['status']) ? trim($_GET['status']) : '';

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'agent_type' => $agentType, 'status' => $status]);
$url   = $base . '/public/agentedge/buyback/admin/drafts?' . $qs;

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$resp   = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the CRM: ' . $err]);
    exit;
}
http_response_code($status_code ?: 502);
echo $resp;
