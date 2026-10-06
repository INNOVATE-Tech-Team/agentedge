<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/crm_email_assertion.php';
ini_set('display_errors', '0');
ob_clean();

$agent = current_agent();
if (!$agent) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_use_buyback()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$address = trim($body['address'] ?? '');
$subjectKey = trim($body['subject_listing_key'] ?? '');
$compKeys = is_array($body['comp_listing_keys'] ?? null) ? array_values($body['comp_listing_keys']) : [];
if ($address === '') { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'address is required']); exit; }
if (empty($compKeys)) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Select at least one comp first.']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'email' => crm_signed_email($agent['email'])]);
$url   = $base . '/public/agentedge/buyback/cma-pdf?' . $qs;

$payload = json_encode([
    'address' => $address,
    'subject_listing_key' => $subjectKey !== '' ? $subjectKey : null,
    'comp_listing_keys' => $compKeys,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Could not reach the CRM: ' . $err]);
    exit;
}

// Non-PDF response means the CRM sent back a JSON error instead of a PDF —
// pass it through as-is rather than trying to serve broken bytes as a "PDF".
if ($status >= 400 || strpos((string)$ctype, 'application/pdf') === false) {
    http_response_code($status ?: 502);
    header('Content-Type: application/json');
    $decoded = json_decode($resp, true);
    echo json_encode(['ok' => false, 'error' => $decoded['detail'] ?? $decoded['error'] ?? 'Could not generate the CMA PDF.']);
    exit;
}

$fname = 'CMA-' . preg_replace('/[^A-Za-z0-9]+/', '-', $address);
$fname = trim(substr($fname, 0, 60), '-') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($resp));
echo $resp;
