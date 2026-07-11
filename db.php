<?php
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

// --- Mode helpers -----------------------------------------------------------
function demo_login(): bool {
    $c = cfg();
    return empty($c['auth_bridge_url']) && !empty($c['demo']);
}
function writes_enabled(): bool {
    return !demo_login();
}
function sample_dashboard(): bool {
    $c = cfg();
    return !empty($c['sample_dashboard']) || demo_login();
}

function record_email(string $login_email): string {
    $aliases = cfg()['email_aliases'] ?? [];
    $e = strtolower(trim($login_email));
    foreach ($aliases as $from => $to) {
        if (strtolower(trim($from)) === $e) return strtolower(trim($to));
    }
    return $e;
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

// Run a prepared SELECT and return all rows (assoc).
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

// --- Dotloop write DB (PDO to innovate_dotloop on dotloopapi-db) -----------
// Used only by dotloop API files (token storage). Separate from Perfex read DB.
function db_rw(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $host = $c['db_rw_host'] ?? $c['db_host'];
        $name = $c['db_rw_name'] ?? $c['db_name'];
        $user = $c['db_rw_user'] ?? $c['db_user'];
        $pass = $c['db_rw_pass'] ?? $c['db_pass'];
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function db_exec(string $sql, array $params = []): void {
    $st = db_rw()->prepare($sql);
    $st->execute($params);
}
