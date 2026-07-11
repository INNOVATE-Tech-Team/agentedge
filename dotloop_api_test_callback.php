<?php
// Test results page — shows pagination test results for api@innovateonline.com
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
$testEmail = $_GET['test_email'] ?? 'api_test@innovateonline.com';

$tokens = dotloop_get_tokens($testEmail);
if (!$tokens || empty($tokens['access_token'])) {
    die('No test token found. <a href="dotloop_api_test_start.php">Run OAuth test</a>');
}

echo '<pre style="font-family:monospace;padding:20px;">';
echo "=== dotloop API pagination test ===\n";
echo "Test account: " . htmlspecialchars($testEmail) . "\n\n";

// Fetch profiles
$profileResult = dotloop_api($testEmail, 'GET', '/profile');
$profiles = $profileResult['data']['data'] ?? ($profileResult['data'] ?? []);
if (!is_array($profiles) || (isset($profiles['id']) && !isset($profiles[0]))) {
    $profiles = $profiles ? [$profiles] : [];
}

echo "Profiles found: " . count($profiles) . "\n\n";
foreach ($profiles as $p) {
    $pid  = (string)($p['id']   ?? '');
    $name = $p['name']           ?? '?';
    $type = $p['type']           ?? '?';
    echo "Profile: {$pid} | {$name} | {$type}\n";
    if ($pid === '') continue;

    $r1 = dotloop_api($testEmail, 'GET', "/profile/{$pid}/loop?pg=1");
    $r2 = dotloop_api($testEmail, 'GET', "/profile/{$pid}/loop?pg=2");
    $ids1  = array_column($r1['data']['data'] ?? [], 'id');
    $ids2  = array_column($r2['data']['data'] ?? [], 'id');
    $total = $r1['data']['meta']['total'] ?? '?';

    echo "  Total loops:     {$total}\n";
    echo "  Page 1 first ID: " . ($ids1[0] ?? 'none') . "\n";
    echo "  Page 2 first ID: " . ($ids2[0] ?? 'none') . "\n";
    $works = !empty($ids2) && ($ids1 !== $ids2);
    echo "  Pagination works: " . ($works ? "YES — DIFFERENT PAGES!" : "no (same data)") . "\n\n";
}

// Clean up test token
local_db()->prepare("DELETE FROM dotloop_tokens WHERE agent_email = ?")->execute([$testEmail]);
echo "Test token cleaned up.\n";
echo '</pre>';
echo '<p><a href="dotloop.php">Back to Transactions</a></p>';
