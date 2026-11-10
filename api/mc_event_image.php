<?php
// Market Center Events — serves an event image by its random key. Login
// required (same as api/oh_photo.php). Keys are minted only by
// api/mc_events_action.php action=upload_image.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_login();

$key = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $_GET['key'] ?? '');
if (!$key) { http_response_code(404); exit; }

$cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
$dataDir = $cfgDir ?: (__DIR__ . '/../data');
$path    = $dataDir . '/mc_event_images/' . $key;
if (!is_file($path)) { http_response_code(404); exit; }

header('Content-Type: ' . (mime_content_type($path) ?: 'image/jpeg'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
