<?php
// On-demand decrypt of an agent's personal/corporate tax ID for admin viewing.
// Every reveal is written to tax_id_access_log for audit purposes — this
// endpoint intentionally does NOT return the value as part of any bulk/list
// response, only one value at a time on explicit admin action.
// GET ?email=...&field=personal|corporate
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/crypto.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }

$email = strtolower(trim($_GET['email'] ?? ''));
$field = trim($_GET['field'] ?? '');
if ($email === '' || !in_array($field, ['personal', 'corporate'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'email and field=personal|corporate are required']);
    exit;
}

$col = $field === 'personal' ? 'personal_tax_id_enc' : 'corporate_tax_id_enc';
$st  = local_db()->prepare("SELECT $col FROM agent_intake WHERE email=?");
$st->execute([$email]);
$row = $st->fetch(PDO::FETCH_ASSOC);

$value = $row ? tax_id_decrypt($row[$col] ?? '') : null;

local_db()->prepare(
    "INSERT INTO tax_id_access_log (admin_email, target_email, field) VALUES (?,?,?)"
)->execute([strtolower(trim($agent['email'] ?? '')), $email, $field]);

echo json_encode(['ok' => true, 'value' => $value ?? '']);
