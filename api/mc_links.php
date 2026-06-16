<?php
// Returns market-center-specific resource links for the sidebar.
// ?mc=slug — slugified market center name matching mc_links.php keys.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo '[]'; exit; }

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['mc'] ?? '')));
if ($slug === '') { echo '[]'; exit; }

$all   = require __DIR__ . '/../mc_links.php';
$links = $all[$slug] ?? [];

// Strip placeholder entries (url '#') — only serve real URLs to agents.
$links = array_values(array_filter($links, fn($l) => ($l['url'] ?? '#') !== '#'));

echo json_encode($links);
