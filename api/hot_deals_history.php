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
if (!can_send_hot_deals()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$url   = $base . '/admin/hot-deal-alerts/history' . ($token ? '?token=' . urlencode($token) : '');

$ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "Accept: application/json\r\n"]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the CRM']);
    exit;
}
echo $raw;
