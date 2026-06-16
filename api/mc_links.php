<?php
// Returns market-center-specific resource links for the sidebar.
// ?mc=slug — slugified market center name matching the DB mc_resource_links table.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo '[]'; exit; }

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['mc'] ?? '')));
if ($slug === '') { echo '[]'; exit; }

echo json_encode(mc_resource_links_for($slug));
