<?php
// Event Planner — serves a hero image by its random key. Deliberately PUBLIC
// (no login check): the public RSVP page (event_public.php) has no session,
// and the key itself is an unguessable 128-bit value written only by
// save_ep_event_image() in api/ep_events.php, so exposing it here leaks
// nothing beyond what the page that links to it already reveals.
require_once __DIR__ . '/../db.php';

$key = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $_GET['key'] ?? '');
if (!$key) { http_response_code(404); exit; }

$cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
$dataDir = $cfgDir ?: (__DIR__ . '/../data');
$path    = $dataDir . '/ep_event_images/' . $key;
if (!file_exists($path)) { http_response_code(404); exit; }

$mime = mime_content_type($path) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
