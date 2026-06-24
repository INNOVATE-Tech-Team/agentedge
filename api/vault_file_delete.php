<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/local_db.php';
require_once dirname(__DIR__) . '/lib/s3.php';
header('Content-Type: application/json');
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'admin only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$fileId = $body['file_id'] ?? '';
if (!$fileId) { echo json_encode(['ok'=>false,'error'=>'file_id required']); exit; }

$db   = local_db();
$stmt = $db->prepare("SELECT storage_key FROM vault_files WHERE id=?");
$stmt->execute([$fileId]);
$row  = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

try { s3_delete($row['storage_key']); } catch (\Exception $e) {}

$db->prepare("DELETE FROM vault_files WHERE id=?")->execute([$fileId]);

echo json_encode(['ok'=>true]);
