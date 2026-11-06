<?php
// Migration 20261105_profile_status — explicit, operator-run schema change for
// "profile completeness vs intake submission" (completion-link rules + Needs
// Review holds). The application itself never alters tables; until this has
// been applied it degrades safely (old links keep working, no new links can
// be issued, no review holds).
//
// CLI ONLY. Deliberately does NOT load auth.php / sessions or any app code —
// it talks to the SQLite file directly — so it can't trigger session GC or
// anything else as a side effect.
//
// Usage (run as the web user, e.g. docker exec -u www-data <container> php ...):
//   php 20261105_profile_status.php --db=/path/agentedge.db                 # verify only (default, read-only)
//   php 20261105_profile_status.php --db=... --apply --backup-dir=/path     # backup → apply → verify
//   php 20261105_profile_status.php --db=... --rollback                     # drop what this migration added (only if it holds no data)
//   php 20261105_profile_status.php --db=... --rollback --force --export-dir=/path   # export new data first, verify the export, then drop
//
// What --apply does, in order (stops at the first failure):
//   1. Pre-flight: SQLite >= 3.35, PRAGMA quick_check = ok, target tables
//      exist, records which changes are still needed.
//   2. Backup: VACUUM INTO <backup-dir>/agentedge.db.pre-20261105-<UTC>.db
//      (consistent online copy), then quick_check + row-count comparison of
//      the backup against the live DB for the affected tables.
//   3. Apply, in ONE transaction: 4 nullable/defaulted columns on
//      profile_completion_tokens + table agent_review_holds + a UNIQUE
//      partial index that lets the database itself refuse a second active
//      hold for the same email.
//      Additive only: no existing row or value is modified; the 312+
//      historical links keep expires_at NULL (= never auto-expire).
//   4. Post-verify: columns/table/index present, row counts unchanged,
//      quick_check ok, every pre-existing token still has expires_at NULL.
// Idempotent: re-running --apply when already applied changes nothing.
//
// Rollback (see also the runbook): first roll the CODE back (the previous code
// ignores these columns/table, so a code-only rollback is already safe). Then,
// only if the schema itself must go: --rollback drops the index, the
// agent_review_holds table and the 4 columns (ALTER TABLE ... DROP COLUMN).
// It never drops data silently: if any hold exists, or any link carries an
// expiry / revocation / override, it refuses unless given --force AND
// --export-dir; it then takes the write lock, writes every hold row and every
// link's new-column values to a JSON export, re-reads and checks the export,
// and only then drops — all in one transaction. Last resort: restore the
// backup file (loses writes made after it was taken — see the runbook).
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

const MIG_ID      = '20261105_profile_status';
const TOKEN_COLS  = [
    'expires_at'        => 'ALTER TABLE profile_completion_tokens ADD COLUMN expires_at TEXT',
    'revoked_at'        => 'ALTER TABLE profile_completion_tokens ADD COLUMN revoked_at TEXT',
    'revoked_reason'    => 'ALTER TABLE profile_completion_tokens ADD COLUMN revoked_reason TEXT',
    'cooldown_override' => 'ALTER TABLE profile_completion_tokens ADD COLUMN cooldown_override INTEGER NOT NULL DEFAULT 0',
];
const HOLDS_SQL = "CREATE TABLE agent_review_holds (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL,
    reason_code     TEXT NOT NULL,
    reason_note     TEXT NOT NULL DEFAULT '',
    source          TEXT NOT NULL DEFAULT 'staff',
    placed_by       TEXT NOT NULL,
    placed_at       TEXT NOT NULL DEFAULT (datetime('now')),
    resolved_at     TEXT,
    resolved_by     TEXT,
    resolution_note TEXT
)";
const HOLDS_INDEX     = 'idx_agent_review_holds_one_active';
// At most ONE active (unresolved) hold per email, enforced by SQLite itself.
const HOLDS_INDEX_SQL = "CREATE UNIQUE INDEX " . HOLDS_INDEX . " ON agent_review_holds(email) WHERE resolved_at IS NULL";
const COUNT_TABLES = ['profile_completion_tokens', 'agent_intake', 'onboard_queue', 'notification_queue'];

$opt = getopt('', ['db:', 'apply', 'rollback', 'force', 'backup-dir:', 'export-dir:']);
$dbPath = $opt['db'] ?? '';
if ($dbPath === '' || !is_file($dbPath)) { fwrite(STDERR, "--db=<path to agentedge.db> is required and must exist\n"); exit(2); }
$mode = isset($opt['apply']) ? 'apply' : (isset($opt['rollback']) ? 'rollback' : 'verify');

function out(string $s): void { echo $s, "\n"; }
function fail(string $s): void { fwrite(STDERR, "FAILED: $s\n"); exit(1); }
function pdo(string $path): PDO {
    $p = new PDO('sqlite:' . $path);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout=15000');
    return $p;
}
function cols(PDO $p, string $t): array { return array_column($p->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name'); }
function has_table(PDO $p, string $t): bool { $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"); $s->execute([$t]); return (bool)$s->fetchColumn(); }
function has_index(PDO $p, string $i): bool { $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?"); $s->execute([$i]); return (bool)$s->fetchColumn(); }
function counts(PDO $p): array { $c = []; foreach (COUNT_TABLES as $t) if (has_table($p, $t)) $c[$t] = (int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(); return $c; }
function state(PDO $p): array {
    $tc = cols($p, 'profile_completion_tokens');
    return [
        'missing_cols' => array_values(array_diff(array_keys(TOKEN_COLS), $tc)),
        'present_cols' => array_values(array_intersect(array_keys(TOKEN_COLS), $tc)),
        'holds_table'  => has_table($p, 'agent_review_holds'),
        'holds_index'  => has_index($p, HOLDS_INDEX),
    ];
}
function preflight(PDO $p): array {
    $v = $p->query('SELECT sqlite_version()')->fetchColumn();
    out("SQLite version: $v");
    if (version_compare($v, '3.35.0', '<')) fail("SQLite >= 3.35 required (DROP COLUMN for rollback)");
    $qc = $p->query('PRAGMA quick_check')->fetchColumn();
    out("quick_check: $qc");
    if ($qc !== 'ok') fail('database quick_check is not ok — do not migrate');
    foreach (['profile_completion_tokens', 'agent_intake'] as $t) if (!has_table($p, $t)) fail("required table $t is missing");
    $s = state($p);
    out('profile_completion_tokens: present=' . json_encode($s['present_cols']) . ' missing=' . json_encode($s['missing_cols']));
    out('agent_review_holds table: ' . ($s['holds_table'] ? 'present' : 'missing') . '; index: ' . ($s['holds_index'] ? 'present' : 'missing'));
    $c = counts($p); out('row counts: ' . json_encode($c));
    return [$s, $c];
}

$p = pdo($dbPath);
out("== Migration " . MIG_ID . " — mode: $mode — db: $dbPath");
[$state, $before] = preflight($p);
$applied = !$state['missing_cols'] && $state['holds_table'] && $state['holds_index'];

if ($mode === 'verify') {
    out($applied ? 'STATUS: APPLIED' : (($state['present_cols'] || $state['holds_table']) ? 'STATUS: PARTIALLY APPLIED — investigate before proceeding' : 'STATUS: NOT APPLIED'));
    exit(0);
}

if ($mode === 'apply') {
    if ($applied) { out('Already applied — nothing to do.'); exit(0); }
    if ($state['present_cols'] || $state['holds_table']) fail('partially applied state found — investigate manually (see runbook) before applying');
    $dir = rtrim($opt['backup-dir'] ?? '', '/');
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) fail('--backup-dir=<existing writable dir> is required for --apply');
    $bk = $dir . '/agentedge.db.pre-' . MIG_ID . '-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.db';
    if (file_exists($bk)) fail("backup target already exists: $bk");
    out("Backup: VACUUM INTO $bk");
    try { $p->exec('VACUUM INTO ' . $p->quote($bk)); }
    catch (\Throwable $e) { fail('backup failed, nothing changed: ' . $e->getMessage()); }
    $bp = pdo($bk);
    if ($bp->query('PRAGMA quick_check')->fetchColumn() !== 'ok') fail('backup quick_check failed');
    $bc = counts($bp);
    if ($bc !== $before) fail('backup row counts differ from live DB: ' . json_encode($bc));
    out('Backup verified (quick_check ok, row counts match). sha256=' . hash_file('sha256', $bk));
    $bp = null;

    out('Applying (single transaction)…');
    $p->beginTransaction();
    try {
        foreach (TOKEN_COLS as $sql) $p->exec($sql);
        $p->exec(HOLDS_SQL);
        $p->exec(HOLDS_INDEX_SQL);
        $p->commit();
    } catch (\Throwable $e) {
        $p->rollBack();
        fail('apply error, transaction rolled back, nothing changed: ' . $e->getMessage());
    }

    out('Post-verify…');
    $after = state($p);
    if ($after['missing_cols'] || !$after['holds_table'] || !$after['holds_index']) fail('post-verify: schema incomplete ' . json_encode($after));
    if (counts($p) !== $before) fail('post-verify: row counts changed ' . json_encode(counts($p)));
    if ($p->query('PRAGMA quick_check')->fetchColumn() !== 'ok') fail('post-verify: quick_check not ok');
    $nonNull = (int)$p->query('SELECT COUNT(*) FROM profile_completion_tokens WHERE expires_at IS NOT NULL OR revoked_at IS NOT NULL')->fetchColumn();
    if ($nonNull !== 0) fail("post-verify: $nonNull historical links unexpectedly have expiry/revocation set");
    out('STATUS: APPLIED. Historical links unchanged (expires_at NULL). Backup: ' . $bk);
    exit(0);
}

if ($mode === 'rollback') {
    if (!$state['present_cols'] && !$state['holds_table'] && !$state['holds_index']) { out('Nothing to roll back.'); exit(0); }
    // Take the write lock first so nothing can be written between the export and the drop.
    $p->exec('BEGIN IMMEDIATE');
    $holds = $state['holds_table'] ? $p->query('SELECT * FROM agent_review_holds ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
    $linkCols = $state['present_cols'];
    $links = [];
    if ($linkCols) {
        $conds = [];
        foreach ($linkCols as $c) $conds[] = $c === 'cooldown_override' ? 'COALESCE(cooldown_override,0) != 0' : "$c IS NOT NULL";
        $links = $p->query('SELECT token, email, created_at, ' . implode(', ', $linkCols) . ' FROM profile_completion_tokens WHERE ' . implode(' OR ', $conds) . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    out('Data held only in what this migration added: ' . count($holds) . ' review hold(s), ' . count($links) . ' link(s) with expiry/revocation/override set');
    if ($holds || $links) {
        if (!isset($opt['force'])) { $p->exec('ROLLBACK'); fail('refusing: rollback would drop the data above. Export it and drop with --force --export-dir=<dir> (or roll back the CODE only — see the runbook)'); }
        $dir = rtrim($opt['export-dir'] ?? '', '/');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) { $p->exec('ROLLBACK'); fail('--export-dir=<existing writable dir> is required when data would be dropped; nothing changed'); }
        $ex = $dir . '/rollback-export-' . MIG_ID . '-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.json';
        $payload = json_encode(['migration' => MIG_ID, 'exported_at_utc' => gmdate('c'), 'db' => $dbPath,
                                'agent_review_holds' => $holds, 'profile_completion_tokens_new_columns' => $links],
                               JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_exists($ex) || file_put_contents($ex, $payload, LOCK_EX) !== strlen($payload)) { $p->exec('ROLLBACK'); fail("could not write export $ex; nothing changed"); }
        $chk = json_decode((string)file_get_contents($ex), true);
        if (!is_array($chk) || $chk['agent_review_holds'] != $holds || $chk['profile_completion_tokens_new_columns'] != $links) { $p->exec('ROLLBACK'); fail("export $ex did not read back identically; nothing changed"); }
        out("Export written and verified: $ex  sha256=" . hash_file('sha256', $ex));
    }
    out('Rolling back (single transaction)…');
    try {
        if ($state['holds_index']) $p->exec('DROP INDEX ' . HOLDS_INDEX);
        if ($state['holds_table']) $p->exec('DROP TABLE agent_review_holds');
        foreach (array_reverse($state['present_cols']) as $c) $p->exec("ALTER TABLE profile_completion_tokens DROP COLUMN $c");
        $p->exec('COMMIT');
    } catch (\Throwable $e) {
        try { $p->exec('ROLLBACK'); } catch (\Throwable $e2) {}
        fail('rollback error, transaction rolled back, nothing changed: ' . $e->getMessage());
    }
    $s = state($p);
    if ($s['present_cols'] || $s['holds_table'] || $s['holds_index']) fail('rollback verify failed ' . json_encode($s));
    if (counts($p) !== array_intersect_key($before, counts($p))) fail('rollback verify: row counts changed');
    out('STATUS: ROLLED BACK (schema back to pre-migration; other data untouched).');
    exit(0);
}
