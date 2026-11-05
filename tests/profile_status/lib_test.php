<?php
// Shared helpers for the Profile Status tests. ISOLATED DEV CONTAINERS ONLY:
// every run wipes /var/dev-data/agentedge.db and rebuilds it from the
// schema-only template (no production rows), and every account is a
// synthetic @test.invalid address. Refuses to run anywhere else.
//
// Deliberately uses only plain PDO + HTTP (no new application functions), so
// the onboarding regression suite can run unchanged against the BASELINE
// (current production) code as well as the feature branch.

const APP_DIR   = '/var/www/html';
const DATA_DIR  = '/var/dev-data';
const TEMPLATE  = '/opt/dev-support/schema-template.db';
const BASE_URL  = 'http://localhost';
const NOT_NEW_AGENT_SUBJECT = 'updated their AgentEdge profile'; // the only email an existing agent's own save may queue

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$cfg = @include APP_DIR . '/config.php';
if (!is_array($cfg) || ($cfg['local_db_dir'] ?? '') !== DATA_DIR || ($cfg['sendgrid_key'] ?? 'x') !== '' || !is_file(TEMPLATE)) {
    fwrite(STDERR, "REFUSING TO RUN: not the isolated dev environment (dev config / template missing).\n");
    exit(2);
}

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'section' => ''];

function section(string $name): void { $GLOBALS['T']['section'] = $name; echo "\n== $name\n"; }
function ok(bool $cond, string $name, $detail = ''): bool {
    $s = $GLOBALS['T']['section'];
    if ($cond) { $GLOBALS['T']['pass']++; echo "  PASS  $name\n"; }
    else {
        $GLOBALS['T']['fail']++;
        $d = is_string($detail) ? $detail : json_encode($detail);
        $GLOBALS['T']['fails'][] = "[$s] $name" . ($d !== '' ? " — $d" : '');
        echo "  FAIL  $name" . ($d !== '' ? "  ($d)" : '') . "\n";
    }
    return $cond;
}
function summary(): int {
    $t = $GLOBALS['T'];
    echo "\n" . str_repeat('-', 60) . "\n{$t['pass']} passed, {$t['fail']} failed\n";
    foreach ($t['fails'] as $f) echo "  - $f\n";
    return $t['fail'] ? 1 : 0;
}

function reset_db(): void {
    foreach (glob(DATA_DIR . '/agentedge.db*') as $f) unlink($f);
    copy(TEMPLATE, DATA_DIR . '/agentedge.db');
    foreach (glob('/tmp/ps-cookie-*') as $f) unlink($f);
}
function tdb(): PDO {
    $p = new PDO('sqlite:' . DATA_DIR . '/agentedge.db');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout=15000');
    return $p;
}
function tq(string $sql, array $a = []): array { $s = tdb()->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
function tq1(string $sql, array $a = []) { $s = tdb()->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function tx(string $sql, array $a = []): void { tdb()->prepare($sql)->execute($a); }

// ── HTTP (curl inside the container → its own Apache; nothing leaves the box)
function http_req(?string $user, string $method, string $path, $json = null): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false];
    if ($user !== null) {
        $jar = '/tmp/ps-cookie-' . preg_replace('/[^a-z0-9]/', '_', $user);
        if (!is_file($jar)) {
            $l = curl_init(BASE_URL . '/__test_login?as=' . urlencode($user) . '&next=/index.php');
            curl_setopt_array($l, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
            curl_exec($l); $lc = curl_getinfo($l, CURLINFO_HTTP_CODE); unset($l); // PHP 8 writes the jar when the handle is destroyed
            if ($lc !== 302) { fwrite(STDERR, "test login failed for $user ($lc)\n"); }
        }
        $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($json !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($json); $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

// ── Fixtures (all synthetic) ────────────────────────────────────────────────
function full_row(string $email, string $name, array $over = []): array {
    return array_merge([
        'email' => $email, 'full_name' => $name, 'phone' => '555' . str_pad((string)(hexdec(substr(md5($email), 0, 8)) % 10000000), 7, '0', STR_PAD_LEFT), // unique per synthetic email
        'license_number' => 'LIC' . (crc32($email) % 100000), 'license_state' => 'SC', 'nar_number' => 'NAR' . (crc32($email) % 100000),
        'office_location' => 'Testville', 'birthday' => '1985-04-12', 'address_line1' => '100 Test Street',
        'city' => 'Testville', 'state' => 'SC', 'zip' => '29000', 'emergency_name' => 'Pat Example',
        'emergency_phone' => '5550199000', 'bio' => 'Synthetic test agent bio used only in the isolated test environment.',
        'referring_agent' => 'Sam Sponsor (sponsor@test.invalid)', 'country' => 'United States',
    ], $over);
}
function insert_intake(array $row, int $submitted = 0, ?string $submittedAt = null): void {
    $row['submitted'] = $submitted; $row['submitted_at'] = $submittedAt ?? ($submitted ? '2026-07-01 12:00:00' : null);
    $row['updated_at'] = '2026-07-11 13:19:25';
    $cols = array_keys($row);
    tx('INSERT INTO agent_intake (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
}
function add_queue(string $email, string $name, string $status = 'active', ?string $intakeSentAt = null): int {
    tx("INSERT INTO onboard_queue (agent_email, agent_name, market_center, start_date, sponsor, role, added_by, status, intake_sent_at)
       VALUES (?, ?, 'Testville', '2026-11-15', '', 'agent', 'admin@test.invalid', ?, ?)", [$email, $name, $status, $intakeSentAt]);
    return (int)tq1('SELECT MAX(id) FROM onboard_queue');
}
function add_role(string $email, string $role): void {
    tx("INSERT INTO agent_roles (email, role) VALUES (?, ?)", [$email, $role]);
}
function seed_base(): void {
    add_role('super@test.invalid', 'super_admin');
    add_role('admin@test.invalid', 'admin');
    tx("INSERT INTO market_centers (slug, name, state_code, enabled, bic_email, mc_leader_email) VALUES ('testville','Testville','SC',1,'bic@test.invalid','mcl@test.invalid')");
}
function max_notif(): int { return (int)tq1('SELECT COALESCE(MAX(id),0) FROM notification_queue'); }
function notifs_since(int $id): array { return tq('SELECT recipient, subject FROM notification_queue WHERE id > ? ORDER BY id', [$id]); }
function new_agent_notifs(array $rows): array {
    return array_values(array_filter($rows, fn($r) => strpos($r['subject'], NOT_NEW_AGENT_SUBJECT) === false));
}
function intake_post_body(string $email, array $over = []): array {
    // The full payload the self-service Intake Form sends (whole form each time).
    $r = full_row($email, $over['full_name'] ?? 'Test Agent', $over);
    unset($r['email']);
    return $r + ['full_time' => true, 'show_on_internet' => true];
}

// ── Mock CRM (throwaway MariaDB container with synthetic rows ONLY) ─────────
// Stands in for Perfex's tblstaff / tblre_transaction_agents so the growth-
// network (sponsor chain) emails can be exercised. Never the live CRM: the
// dev config points db_host at agentedge-profile-mockcrm on the internal net.
function crm(): ?mysqli {
    $c = include APP_DIR . '/config.php';
    if (($c['db_host'] ?? '') !== 'agentedge-profile-mockcrm') { fwrite(STDERR, "REFUSING: CRM host is not the mock\n"); exit(2); }
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $m->connect_errno ? null : $m;
}
// Synthetic sponsor tree:  Upline Sponsor (2) ← Sam Sponsor (1) ← each agent passed in.
function crm_reset(array $agentEmails): bool {
    $m = crm(); if (!$m) return false;
    $m->query("DROP TABLE IF EXISTS tblre_transaction_agents");
    $m->query("DROP TABLE IF EXISTS tblstaff");
    $m->query("CREATE TABLE tblstaff (staffid INT PRIMARY KEY, email VARCHAR(190), firstname VARCHAR(100), lastname VARCHAR(100), active INT DEFAULT 1, phonenumber VARCHAR(40) DEFAULT '', password VARCHAR(255) DEFAULT '', admin INT DEFAULT 0, role INT DEFAULT 0, datecreated DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $m->query("CREATE TABLE tblre_transaction_agents (agent_id INT PRIMARY KEY, recruit_source_agent_id INT NULL)");
    $m->query("INSERT INTO tblstaff (staffid,email,firstname,lastname) VALUES (1,'sponsor@test.invalid','Sam','Sponsor'),(2,'upline@test.invalid','Uma','Upline')");
    $m->query("INSERT INTO tblre_transaction_agents VALUES (1,2),(2,NULL)");
    $id = 100;
    foreach ($agentEmails as $e) {
        $st = $m->prepare("INSERT INTO tblstaff (staffid,email,firstname,lastname) VALUES (?,?,'Test','Agent')"); $st->bind_param('is', $id, $e); $st->execute();
        $st = $m->prepare("INSERT INTO tblre_transaction_agents VALUES (?,1)"); $st->bind_param('i', $id); $st->execute();
        $id++;
    }
    return true;
}
function growth_notifs(array $rows): array {
    return array_values(array_filter($rows, fn($r) => strpos($r['subject'], 'New Agent in Your Growth Network') === 0));
}
