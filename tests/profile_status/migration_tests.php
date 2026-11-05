<?php
// Migration 20261105_profile_status + "the app never alters tables".
// Runs against copies of the schema-only template (production's structure,
// no rows) in the isolated dev container.
require __DIR__ . '/lib_test.php';
const MIG = APP_DIR . '/scripts/migrations/20261105_profile_status.php';
const MDB = DATA_DIR . '/migration-test.db';
const BK  = DATA_DIR . '/migration-backups';

function mig(string $args): array {
    exec('php ' . MIG . ' --db=' . escapeshellarg(MDB) . ' ' . $args . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}
function mdb(): PDO { $p = new PDO('sqlite:' . MDB); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }
function tokcols(): array { return array_column(mdb()->query("PRAGMA table_info(profile_completion_tokens)")->fetchAll(PDO::FETCH_ASSOC), 'name'); }
function holds_table(): bool { return (bool)mdb()->query("SELECT 1 FROM sqlite_master WHERE name='agent_review_holds'")->fetchColumn(); }
function schema_sig(PDO $p): string { return md5(implode("\n", $p->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN))); }

// ── A. The application never alters the schema ──────────────────────────────
section('A. Application does not alter tables (pre-migration database)');
reset_db(); seed_base();
insert_intake(full_row('legacy.agent@test.invalid', 'Test Legacy', ['zip' => '']), 0);
tx("INSERT INTO profile_completion_tokens (token, email, created_at, created_by) VALUES ('legacy0000000000000000000000000000000000000000ab', 'legacy.agent@test.invalid', datetime('now','-90 days'), 'staff@test.invalid')");
$before = schema_sig(tdb());
$r1 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => 'legacy.agent@test.invalid']);
$r2 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_preview']);
$r3 = http_req(null, 'GET', '/api/complete_profile_action.php?token=legacy0000000000000000000000000000000000000000ab');
$r4 = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php');
$r5 = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => 'legacy.agent@test.invalid', 'reason_code' => 'duplicate']);
$r6 = http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=legacy.agent%40test.invalid');
ok(schema_sig(tdb()) === $before, 'no table or column was created/altered by any page or endpoint');
ok(($r1['json']['migration_pending'] ?? false) === true && ($r2['json']['migration_pending'] ?? false) === true, 'issuing new links is refused with a clear "migration pending" message', [$r1['body'], $r2['body']]);
ok(($r3['json']['ok'] ?? false) === true, 'historical link still works exactly as before', $r3['body']);
ok($r4['code'] === 200 && strpos($r4['body'], 'data-tab="needs-review"') !== false, 'Agents page renders (no holds yet)');
ok(($r5['json']['migration_pending'] ?? false) === true, 'review holds unavailable until migration');
ok(($r6['json']['ok'] ?? false) === true && strpos($r6['json']['html'] ?? '', 'once migration 20261105_profile_status is applied') !== false, 'detail panel explains holds await the migration');

// ── B. The migration script ─────────────────────────────────────────────────
section('B. Migration: verify → apply (with backup) → verify → idempotent');
@unlink(MDB); copy(TEMPLATE, MDB);
foreach (glob(BK . '/*') ?: [] as $f) unlink($f); @mkdir(BK, 0777, true);
$m = mdb();
$m->exec("INSERT INTO profile_completion_tokens (token, email, created_by) VALUES ('h1','a@test.invalid','x'),('h2','b@test.invalid','x'),('h3','c@test.invalid','x')");
$m->exec("INSERT INTO agent_intake (email, full_name) VALUES ('a@test.invalid','Test A')");
$sigBefore = schema_sig($m); $m = null;
$v = mig('');
ok($v['rc'] === 0 && strpos($v['out'], 'STATUS: NOT APPLIED') !== false && strpos($v['out'], 'quick_check: ok') !== false, 'verify (default, read-only) reports NOT APPLIED and integrity ok', $v['out']);
ok(schema_sig(mdb()) === $sigBefore, 'verify changed nothing');
$a = mig('--apply');
ok($a['rc'] !== 0 && strpos($a['out'], '--backup-dir') !== false && schema_sig(mdb()) === $sigBefore, '--apply refuses without a backup directory, nothing changed', $a['out']);
$a = mig('--apply --backup-dir=' . BK);
ok($a['rc'] === 0 && strpos($a['out'], 'STATUS: APPLIED') !== false, 'apply succeeded', $a['out']);
$bk = glob(BK . '/agentedge.db.pre-20261105_profile_status-*.db');
ok(count($bk) === 1, 'one timestamped backup written', $bk);
$bp = new PDO('sqlite:' . $bk[0]);
ok(schema_sig($bp) === $sigBefore && (int)$bp->query('SELECT COUNT(*) FROM profile_completion_tokens')->fetchColumn() === 3, 'backup = exact pre-migration schema and data');
$bp = null;
ok(!array_diff(['expires_at', 'revoked_at', 'revoked_reason', 'cooldown_override'], tokcols()) && holds_table(), '4 columns + agent_review_holds present');
ok((int)mdb()->query("SELECT COUNT(*) FROM profile_completion_tokens WHERE expires_at IS NULL AND revoked_at IS NULL AND cooldown_override = 0")->fetchColumn() === 3, 'historical links untouched (no expiry, not revoked)');
ok((int)mdb()->query("SELECT COUNT(*) FROM agent_intake")->fetchColumn() === 1, 'other data untouched');
$v = mig('');
ok(strpos($v['out'], 'STATUS: APPLIED') !== false, 'verify reports APPLIED');
$a2 = mig('--apply --backup-dir=' . BK);
ok($a2['rc'] === 0 && strpos($a2['out'], 'Already applied') !== false && count(glob(BK . '/*.db')) === 1, 're-running apply is a no-op', $a2['out']);

section('C. Rollback');
mdb()->exec("INSERT INTO agent_review_holds (email, reason_code, placed_by) VALUES ('a@test.invalid','duplicate','admin@test.invalid')");
$rb = mig('--rollback');
ok($rb['rc'] !== 0 && strpos($rb['out'], '--force') !== false && holds_table(), 'rollback refuses while holds exist (export first), nothing dropped', $rb['out']);
$rb = mig('--rollback --force');
ok($rb['rc'] === 0 && strpos($rb['out'], 'ROLLED BACK') !== false, 'forced rollback succeeded', $rb['out']);
ok(schema_sig(mdb()) === $sigBefore, 'schema identical to pre-migration');
ok((int)mdb()->query('SELECT COUNT(*) FROM profile_completion_tokens')->fetchColumn() === 3 && (int)mdb()->query('SELECT COUNT(*) FROM agent_intake')->fetchColumn() === 1, 'data intact after rollback');
$a3 = mig('--apply --backup-dir=' . BK);
ok($a3['rc'] === 0 && strpos($a3['out'], 'STATUS: APPLIED') !== false, 're-apply after rollback works');

section('D. Safety checks');
@unlink(MDB); copy(TEMPLATE, MDB);
mdb()->exec("ALTER TABLE profile_completion_tokens ADD COLUMN expires_at TEXT");
$p = mig('--apply --backup-dir=' . BK);
ok($p['rc'] !== 0 && strpos($p['out'], 'partially applied') !== false, 'partially-applied database detected; apply refuses', $p['out']);
$w = http_req(null, 'GET', '/scripts/migrations/20261105_profile_status.php');
ok($w['code'] === 404 && $w['body'] === '', 'migration script is not runnable over the web (CLI only)', $w['code']);
$w = http_req(null, 'GET', '/scripts/review_holds_seed.php');
ok($w['code'] === 404 && $w['body'] === '', 'seed script is not runnable over the web (CLI only)', $w['code']);
ok(strpos(file_get_contents(MIG), "require") === false || preg_match('/require[^;]*auth\.php/', file_get_contents(MIG)) === 0, 'migration never loads auth.php / sessions');

@unlink(MDB);
exit(summary());
