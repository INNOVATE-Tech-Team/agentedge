<?php
// TEMP debugging — shows errors on the page while we get set up. Remove these
// two lines before go-live.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Read-only MySQL connection (mysqli) to the Perfex database. AgentEdge only
// ever runs SELECTs. Uses mysqli (which this PHP already has) rather than PDO.

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            exit('AgentEdge is not configured yet (missing config.php).');
        }
        $cfg = require $path;
    }
    return $cfg;
}

function db(): mysqli {
    static $m = null;
    if ($m === null) {
        $c = cfg();
        $m = @new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
        if ($m->connect_errno) {
            http_response_code(500);
            exit('Database connection failed: ' . $m->connect_error);
        }
        $m->set_charset('utf8mb4');
    }
    return $m;
}

// Run a prepared SELECT and return all rows (assoc). Params bind as strings —
// MySQL coerces them fine for our integer keys.
function db_query(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    if (!$stmt) return [];
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function db_one(string $sql, array $params = []): ?array {
    $rows = db_query($sql, $params);
    return $rows[0] ?? null;
}
