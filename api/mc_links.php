<?php
// Returns market-center-specific resource links for the sidebar.
// ?mc=slug or ?mc[]=slug&mc[]=slug2 — an agent can be assigned to more than
// one market center, so this returns the union of each one's resources.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo '[]'; exit; }

$raw   = $_GET['mc'] ?? '';
$slugs = array_values(array_unique(array_filter(array_map(
    fn($s) => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($s))),
    is_array($raw) ? $raw : [$raw]
))));
if (!$slugs) { echo '[]'; exit; }

$links = [];
foreach ($slugs as $slug) {
    $links = array_merge($links, mc_resource_links_for($slug));
}
echo json_encode($links);
