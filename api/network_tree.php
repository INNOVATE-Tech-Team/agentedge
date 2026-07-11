<?php
// Returns the full network tree for a given agent email.
// Leaders can query any email; agents can only query their own.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$email = trim($_GET['email'] ?? '');
if (!can_search_network()) $email = $me['email'];
if ($email === '') $email = $me['email'];

// Look up the root agent's staffid
$root = db_one("SELECT staffid FROM tblstaff WHERE email = ? LIMIT 1", [$email]);
if (!$root) {
    echo json_encode(['tree'=>null,'totalCount'=>0,'sponsor'=>null]);
    exit;
}
$rootId = (string)$root['staffid'];

// Load all agent nodes + parent relationships in one query
$rows = db_query(
    "SELECT t.agent_id, t.recruit_source_agent_id,
            t.agent_total_sales_volume, t.agent_total_closed_deals,
            t.agent_residual_income_earned,
            s.firstname, s.lastname, s.email AS agent_email
     FROM tblre_transaction_agents t
     LEFT JOIN tblstaff s ON s.staffid = t.agent_id"
);

$nodes = []; $children = []; $parents = [];
foreach ($rows as $row) {
    $id = (string)$row['agent_id'];
    $nodes[$id] = [
        'name'     => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'Agent',
        'email'    => $row['agent_email'] ?? '',
        'volume'   => (float)$row['agent_total_sales_volume'],
        'deals'    => (int)$row['agent_total_closed_deals'],
        'residual' => (float)$row['agent_residual_income_earned'],
    ];
    $parent = (string)($row['recruit_source_agent_id'] ?? '');
    if ($parent !== '' && $parent !== '0') {
        $children[$parent][] = $id;
        $parents[$id]        = $parent;
    }
}

// Recruit Source overrides — set on the Agent Profiles back office page
// (agent_admin.recruit_source_email), since AgentEdge can't write back to
// the Perfex tblre_transaction_agents.recruit_source_agent_id column. Any
// agent with an override here takes precedence over the CRM relationship.
$emailToId = [];
foreach ($nodes as $id => $n) {
    if (!empty($n['email'])) $emailToId[strtolower($n['email'])] = $id;
}
try {
    $overrides = local_db()->query(
        "SELECT email, recruit_source_email FROM agent_admin WHERE recruit_source_email <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($overrides as $o) {
        $childId  = $emailToId[strtolower(trim($o['email']))] ?? null;
        $sourceId = $emailToId[strtolower(trim($o['recruit_source_email']))] ?? null;
        if ($childId === null || $sourceId === null || $childId === $sourceId) continue;
        $oldParent = $parents[$childId] ?? null;
        if ($oldParent !== null && isset($children[$oldParent])) {
            $children[$oldParent] = array_values(array_diff($children[$oldParent], [$childId]));
        }
        $parents[$childId] = $sourceId;
        $children[$sourceId][] = $childId;
    }
} catch (\Exception $e) {}

function buildTree(string $id, array &$nodes, array &$children, int $depth): ?array {
    if ($depth > 6 || !isset($nodes[$id])) return null;
    $node = $nodes[$id];
    $node['children'] = [];
    foreach ($children[$id] ?? [] as $childId) {
        $child = buildTree($childId, $nodes, $children, $depth + 1);
        if ($child !== null) $node['children'][] = $child;
    }
    usort($node['children'], fn($a, $b) => $b['volume'] <=> $a['volume']);
    return $node;
}
function countTree(array $node): int {
    $n = 1;
    foreach ($node['children'] as $c) $n += countTree($c);
    return $n;
}

$tree      = buildTree($rootId, $nodes, $children, 0);
$total     = $tree ? countTree($tree) - 1 : 0;
$sponsorId = $parents[$rootId] ?? '';
$sponsor   = ($sponsorId !== '' && $sponsorId !== '0' && isset($nodes[$sponsorId])) ? $nodes[$sponsorId] : null;

echo json_encode(['tree' => $tree, 'totalCount' => $total, 'sponsor' => $sponsor]);
