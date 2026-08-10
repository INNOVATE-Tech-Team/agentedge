<?php
// Tracked redirect for external nav links (SSO systems like Advantage, MLS
// portals, etc.) — these navigate off-domain, so a normal page load can't
// log them the way nav.php's render_sidebar() logs internal page views.
// The destination URL always comes from nav_resolve_external()'s
// pre-registered lookup, never from a query-string parameter, so this can't
// be used as an open redirect.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
$key   = $_GET['key'] ?? '';
$item  = nav_resolve_external($key);

if (!$item) { header('Location: index.php'); exit; }

nav_log_page_view($key, 'external', $agent['email'] ?? '');

header('Location: ' . $item['url']);
exit;
