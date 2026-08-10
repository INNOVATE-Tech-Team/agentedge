<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['results' => []]); exit; }

// FTS5 MATCH treats *, ", and other punctuation as query syntax -- a plain
// instruction search should never 500 just because someone typed a stray
// quote. Quote each whitespace-separated term and OR them together so a
// multi-word query still matches any of its words, same tolerance an agent
// would expect from a normal search box.
$terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
$match = implode(' OR ', array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"*', $terms));

$db = local_db();
try {
    $st = $db->prepare(
        "SELECT a.page_key, a.label, a.href,
                snippet(howto_search, 2, '<mark>', '</mark>', '…', 12) AS snippet
         FROM howto_search
         JOIN howto_articles a ON a.id = howto_search.rowid
         WHERE howto_search MATCH ? AND a.is_stale = 0
         ORDER BY rank
         LIMIT 8"
    );
    $st->execute([$match]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // A malformed FTS5 query should degrade to "no results", not a 500.
    echo json_encode(['results' => []]);
    exit;
}

echo json_encode([
    'results' => array_map(fn($r) => [
        'pageKey' => $r['page_key'],
        'label'   => $r['label'],
        'href'    => $r['href'],
        'snippet' => $r['snippet'],
    ], $rows),
]);
