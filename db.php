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
// demo_login(): any-password preview login (only when no real auth is set up).
// writes_enabled(): can we save profile edits / create agents? (real auth only)
// sample_dashboard(): show sample tiles/cap instead of querying a local DB.
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

// Some people log in with a different email than their CRM roster record uses
// (e.g. a Perfex login that differs from the agent record). Map login -> record
// email in config 'email_aliases'. Returns the lowercased record email to match.
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
