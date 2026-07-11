<?php
require '/var/www/html/db.php';
require '/var/www/html/lib/dotloop.php';

$ldb = local_db();

// Show all token records
echo "=== Token records ===\n";
$rows = $ldb->query('SELECT agent_email, profile_id FROM dotloop_tokens ORDER BY rowid DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['agent_email'] . ' | profile=' . $r['profile_id'] . "\n";
}

echo "\n=== Pagination test for each account ===\n";
foreach ($rows as $r) {
    $email = $r['agent_email'];
    $tokens = dotloop_get_tokens($email);
    $allProfiles = json_decode($tokens['all_profiles'] ?? '[]', true) ?: [['id'=>$r['profile_id'],'name'=>'default']];
    echo "\n--- {$email} ---\n";
    foreach ($allProfiles as $p) {
        $pid = (string)($p['id'] ?? '');
        if (!$pid) continue;
        $r1 = dotloop_api($email, 'GET', "/profile/{$pid}/loop?pg=1");
        $r2 = dotloop_api($email, 'GET', "/profile/{$pid}/loop?pg=2");
        $ids1  = array_column($r1['data']['data'] ?? [], 'id');
        $ids2  = array_column($r2['data']['data'] ?? [], 'id');
        $total = $r1['data']['meta']['total'] ?? '?';
        $status1 = $r1['status'] ?? '?';
        echo "Profile {$pid} ({$p['name']}): total={$total}, pg1_first=" . ($ids1[0] ?? 'none') . ", pg2_first=" . ($ids2[0] ?? 'none') . ", works=" . (!empty($ids2) && $ids1 !== $ids2 ? "YES!!!" : "no") . "\n";
        if (!$r1['ok']) echo "  pg1 error: status={$status1}\n";
    }
}
