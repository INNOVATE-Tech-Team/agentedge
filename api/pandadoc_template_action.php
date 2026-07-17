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
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $in['state_code'] ?? ''));
    $tmpl = trim($in['template_id'] ?? '');

    if (strlen($code) !== 2) { echo json_encode(['ok'=>false,'error'=>'State code must be 2 letters']); exit; }
    if ($tmpl === '') { echo json_encode(['ok'=>false,'error'=>'Template ID is required']); exit; }

    $db->prepare(
        "INSERT INTO pandadoc_state_templates (state_code, template_id, updated_by, updated_at)
         VALUES (?, ?, ?, datetime('now'))
         ON CONFLICT(state_code) DO UPDATE SET
           template_id=excluded.template_id, updated_by=excluded.updated_by, updated_at=excluded.updated_at"
    )->execute([$code, $tmpl, $agent['email'] ?? '']);

    echo json_encode(['ok'=>true, 'state_code'=>$code, 'template_id'=>$tmpl]);
    exit;
}

if ($action === 'delete') {
    $code = strtoupper(trim($in['state_code'] ?? ''));
    if (!$code) { echo json_encode(['ok'=>false,'error'=>'State code required']); exit; }
    $db->prepare("DELETE FROM pandadoc_state_templates WHERE state_code=?")->execute([$code]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
