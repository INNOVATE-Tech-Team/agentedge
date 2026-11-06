<?php
// Review-hold import / recovery (scripts/review_holds_import.php).
// ISOLATED DEV CONTAINER ONLY: synthetic @test.invalid data on a schema-only DB.
// The export is produced the real way, by the migration's forced schema
// rollback, and restored after the migration is re-applied.
require __DIR__ . '/lib_test.php';
require_once APP_DIR . '/db.php';
require_once APP_DIR . '/local_db.php';
require_once APP_DIR . '/lib/agent_profile.php';

const MIGS = APP_DIR . '/scripts/migrations/20261105_profile_status.php';
const IMP  = APP_DIR . '/scripts/review_holds_import.php';
const SEED = APP_DIR . '/scripts/review_holds_seed.php';
const DBF  = DATA_DIR . '/agentedge.db';
const BK   = DATA_DIR . '/imp-bk';
const WORK = DATA_DIR . '/imp-work';
const ADMIN = 'admin@test.invalid';

function sh(string $cmd): array { exec($cmd . ' 2>&1', $o, $rc); return ['rc' => $rc, 'out' => implode("\n", $o)]; }
function mig(string $a): array { return sh('php ' . MIGS . ' --db=' . DBF . ' ' . $a); }
function imp(string $export, string $a = ''): array { return sh('php ' . IMP . ' --db=' . DBF . ' --export=' . escapeshellarg($export) . ' ' . $a); }
function approve(string $f): string { return '--apply --approved-by=' . ADMIN . ' --expect-sha256=' . hash_file('sha256', $f); }
function holds(): array { return tq('SELECT * FROM agent_review_holds ORDER BY id'); }
function nholds(): int { return (int)tq1('SELECT COUNT(*) FROM agent_review_holds'); }
function needs_review(string $e): bool { profile_schema_cache_reset(); profile_track_cache_reset(); return (bool)profile_status($e)['needs_review']; }
function fresh_dir(string $d): string { @mkdir($d, 0777, true); foreach (glob($d . '/*') ?: [] as $f) unlink($f); return $d; }
function export_to(string $dir): string {   // forced schema rollback → verified JSON export; returns its path
    fresh_dir($dir);
    $r = mig('--rollback --force --export-dir=' . $dir);
    $f = glob($dir . '/rollback-export-*.json');
    if ($r['rc'] !== 0 || count($f) !== 1) { fwrite(STDERR, $r['out'] . "\n"); ok(false, "schema rollback export into $dir"); exit(summary()); }
    return $f[0];
}
function reapply(): bool { $r = mig('--apply --backup-dir=' . BK); profile_schema_cache_reset(); return $r['rc'] === 0 && strpos($r['out'], 'STATUS: APPLIED') !== false; }
function hold_api(array $body): array { return http_req(ADMIN, 'POST', '/api/agent_review_hold.php', $body)['json'] ?? []; }

// ── 0. A realistic hold history ─────────────────────────────────────────────
section('0. Setup: migrated synthetic DB with active + resolved holds');
reset_db(); seed_base();
tdb()->exec('PRAGMA journal_mode=WAL');   // production runs in WAL mode
fresh_dir(BK); fresh_dir(WORK);
foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $k) insert_intake(full_row("imp.$k@test.invalid", 'Test ' . strtoupper($k)), 0);
ok(reapply(), 'migration applied');
$notif0 = max_notif();
$intake0 = tq('SELECT * FROM agent_intake ORDER BY email');
tx("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by, placed_at) VALUES ('imp.a1@test.invalid','duplicate','Same phone as another profile','seed:holds.csv','" . ADMIN . "','2026-11-06 14:00:00')");
tx("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by, placed_at, resolved_at, resolved_by, resolution_note) VALUES (?,?,?,?,?,?,?,?,?)",
   ['imp.a2@test.invalid', 'identity', 'Email mismatch', 'seed:holds.csv', ADMIN, '2026-11-06 14:00:00', '2026-11-07 09:30:00', 'super@test.invalid', "Confirmed with MC leader — \"different person\"; OK\nsecond line, ünïcödé"]);
tx("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by, placed_at, resolved_at, resolved_by, resolution_note) VALUES ('imp.a3@test.invalid','other','','staff','" . ADMIN . "','2026-11-08 10:00:00','2026-11-08 11:00:00','" . ADMIN . "','fixed')");
tx("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by, placed_at) VALUES ('imp.a3@test.invalid','questionable_data','Birthday looks wrong','staff','" . ADMIN . "','2026-11-09 08:15:00')");
$h4 = hold_api(['action' => 'place', 'email' => 'imp.a4@test.invalid', 'reason_code' => 'duplicate', 'note' => 'placed via the page']);
$r4 = hold_api(['action' => 'resolve', 'hold_id' => $h4['hold_id'] ?? 0, 'note' => 'resolved via the page']);
$h5 = hold_api(['action' => 'place', 'email' => 'imp.a5@test.invalid', 'reason_code' => 'identity', 'note' => 'placed via the page']);
ok(($h4['ok'] ?? false) && ($r4['ok'] ?? false) && ($h5['ok'] ?? false), 'holds placed and resolved through the real page API too', [$h4, $r4, $h5]);
tx("INSERT INTO profile_completion_tokens (token, email, created_by, expires_at, revoked_at, revoked_reason) VALUES ('implink', 'imp.a1@test.invalid', '" . ADMIN . "', datetime('now','+30 days'), datetime('now'), 'superseded')");
$SNAP = holds();
ok(count($SNAP) === 6 && count(array_filter($SNAP, fn($r) => $r['resolved_at'] === null)) === 3, '6 holds: 3 active, 3 resolved', $SNAP);
$MAXID = max(array_column($SNAP, 'id'));

// ── A. Schema rollback produces the export ──────────────────────────────────
section('A. Schema rollback writes the export; holds table gone');
$EX = export_to(DATA_DIR . '/imp-exports');
$exDoc = json_decode(file_get_contents($EX), true);
ok(count($exDoc['agent_review_holds']) === 6 && count($exDoc['profile_completion_tokens_new_columns']) === 1, 'export holds all 6 hold rows (+1 link record)');
ok(!tq1("SELECT 1 FROM sqlite_master WHERE name='agent_review_holds'"), 'agent_review_holds dropped by the rollback');

// ── B. Refuses until the migration is re-applied ────────────────────────────
section('B. Import refuses before the migration is re-applied');
$r = imp($EX, approve($EX));
ok($r['rc'] !== 0 && strpos($r['out'], 're-apply migration') !== false && strpos($r['out'], 'Nothing applied') !== false, 'refuses: table missing, nothing applied', $r['out']);
ok(reapply() && nholds() === 0, 'migration re-applied; holds table empty');
$tok0 = tq('SELECT * FROM profile_completion_tokens ORDER BY rowid');   // links after re-apply (new columns back, empty)

// ── C. Dry run ──────────────────────────────────────────────────────────────
section('C. Dry run is read-only and shows exactly what would be restored');
tdb()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$dbSha = hash_file('sha256', DBF);
$r = imp($EX);
ok($r['rc'] === 0 && strpos($r['out'], 'DRY RUN: 6 hold(s) would be restored, 0 already present. Nothing changed.') !== false, 'dry run reports 6 to restore', $r['out']);
ok(substr_count($r['out'], 'WOULD RESTORE') === 6 && strpos($r['out'], '3 active, 3 resolved') !== false && strpos($r['out'], 'resolved 2026-11-07 09:30:00 by super@test.invalid') !== false, 'each hold is listed with status, placer and resolver');
ok(strpos($r['out'], 'export sha256: ' . hash_file('sha256', $EX)) !== false && strpos($r['out'], '1 link expiry/revocation record(s) in this export are NOT restored') !== false, 'prints the export sha256 and says link values are not restored');
ok(hash_file('sha256', DBF) === $dbSha && nholds() === 0, 'database file byte-identical after the dry run');

// ── D. Explicit approval is required to apply ───────────────────────────────
section('D. --apply requires an approver and the reviewed export hash');
$r = imp($EX, '--apply');
ok($r['rc'] === 2 && strpos($r['out'], '--approved-by') !== false && nholds() === 0, 'no approver → refused', $r['out']);
$r = imp($EX, '--apply --approved-by=' . ADMIN);
ok($r['rc'] === 2 && strpos($r['out'], '--expect-sha256') !== false && nholds() === 0, 'no export hash → refused', $r['out']);
$r = imp($EX, '--apply --approved-by=' . ADMIN . ' --expect-sha256=' . str_repeat('0', 64));
ok($r['rc'] !== 0 && strpos($r['out'], 'not the approved') !== false && nholds() === 0, 'wrong hash → refused', $r['out']);
$chg = WORK . '/changed.json'; copy($EX, $chg); $approved = approve($chg); file_put_contents($chg, "\n", FILE_APPEND);
$r = imp($chg, $approved);
ok($r['rc'] !== 0 && strpos($r['out'], 'file changed since the dry run') !== false && nholds() === 0, 'export edited after approval → refused', $r['out']);

// ── E. Invalid exports apply nothing ────────────────────────────────────────
section('E. Invalid exports are rejected before anything is written');
$base = json_decode(file_get_contents($EX), true);
$ia = null; $ir = null;   // indexes of an active and a resolved row
foreach ($base['agent_review_holds'] as $i => $h) { if ($h['resolved_at'] === null) $ia ??= $i; else $ir ??= $i; }
$mut = function (callable $f) use ($base) { $d = $base; $f($d); return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
$cases = [
    'not JSON'                        => 'this is not json',
    'empty file'                      => '',
    'truncated file'                  => substr(file_get_contents($EX), 0, (int)(filesize($EX) / 2)),
    'export of another migration'     => $mut(function (&$d) { $d['migration'] = '20990101_other'; }),
    'holds list missing'              => $mut(function (&$d) { unset($d['agent_review_holds']); }),
    'holds not a list'                => $mut(function (&$d) { $d['agent_review_holds'] = ['x' => $d['agent_review_holds'][0]]; }),
    'extra column'                    => $mut(function (&$d) { $d['agent_review_holds'][0]['surprise'] = 1; }),
    'missing column'                  => $mut(function (&$d) { unset($d['agent_review_holds'][0]['placed_by']); }),
    'invalid id'                      => $mut(function (&$d) { $d['agent_review_holds'][0]['id'] = 0; }),
    'invalid email'                   => $mut(function (&$d) { $d['agent_review_holds'][0]['email'] = 'not-an-email'; }),
    'unknown reason'                  => $mut(function (&$d) { $d['agent_review_holds'][0]['reason_code'] = 'banana'; }),
    'unknown source'                  => $mut(function (&$d) { $d['agent_review_holds'][0]['source'] = 'import'; }),
    'invalid placed_at'               => $mut(function (&$d) { $d['agent_review_holds'][0]['placed_at'] = '2026-13-45 25:00:00'; }),
    'resolved without a note'         => $mut(function (&$d) use ($ir) { $d['agent_review_holds'][$ir]['resolution_note'] = ' '; }),
    'resolved without a resolver'     => $mut(function (&$d) use ($ir) { $d['agent_review_holds'][$ir]['resolved_by'] = null; }),
    'resolved before placed'          => $mut(function (&$d) use ($ir) { $d['agent_review_holds'][$ir]['resolved_at'] = '2000-01-01 00:00:00'; }),
    'half-resolved (note, no date)'   => $mut(function (&$d) use ($ia) { $d['agent_review_holds'][$ia]['resolution_note'] = 'x'; }),
    'duplicate id'                    => $mut(function (&$d) { $d['agent_review_holds'][] = $d['agent_review_holds'][0]; }),
    'two active holds for one agent'  => $mut(function (&$d) use ($ia) { $x = $d['agent_review_holds'][$ia]; $x['id'] = 999; $d['agent_review_holds'][] = $x; }),
];
foreach ($cases as $name => $content) {
    $f = WORK . '/bad-' . md5($name) . '.json'; file_put_contents($f, $content);
    $dry = imp($f); $app = imp($f, approve($f));
    ok($dry['rc'] !== 0 && $app['rc'] !== 0 && strpos($app['out'], 'Nothing applied') !== false && nholds() === 0, "$name → rejected (dry run and apply), nothing written", $app['out']);
}

// ── F. Successful restore ───────────────────────────────────────────────────
section('F. Restore: every record back exactly as it was');
$r = imp($EX, approve($EX));
ok($r['rc'] === 0 && strpos($r['out'], 'Applied: 6 hold(s) restored, 0 already present. Every row verified. Approved by ' . ADMIN) !== false, 'apply restores all 6', $r['out']);
ok(holds() === $SNAP, 'rows identical to before the rollback: ids, reasons, notes, placed by/at, resolved by/at, resolution notes');
ok(needs_review('imp.a1@test.invalid') && !needs_review('imp.a2@test.invalid') && needs_review('imp.a3@test.invalid') && !needs_review('imp.a4@test.invalid') && needs_review('imp.a5@test.invalid'), 'the app sees the same Needs Review state (resolved holds stay resolved)');
ok(tq('SELECT * FROM profile_completion_tokens ORDER BY rowid') === $tok0, 'completion links untouched by the import');
$csv = WORK . '/reseed.csv'; file_put_contents($csv, "email,reason_code,note\nimp.a1@test.invalid,duplicate,x\nimp.a2@test.invalid,identity,x\n");
$s = sh('php ' . SEED . ' --db=' . DBF . ' --csv=' . $csv . ' --apply --by=' . ADMIN);
ok(strpos($s['out'], 'skip (seeded before, resolved 2026-11-07 09:30:00)  imp.a2@test.invalid') !== false && strpos($s['out'], 'skip (already held)  imp.a1@test.invalid') !== false && holds() === $SNAP, 'restored history keeps the seeder from re-opening a resolved hold', $s['out']);

// ── G. Idempotent ───────────────────────────────────────────────────────────
section('G. Re-running is safe');
$r = imp($EX, approve($EX));
ok($r['rc'] === 0 && strpos($r['out'], 'Applied: 0 hold(s) restored, 6 already present') !== false && holds() === $SNAP, 're-run restores nothing, no duplicates', $r['out']);
$r = imp($EX);
ok($r['rc'] === 0 && strpos($r['out'], 'DRY RUN: 0 hold(s) would be restored, 6 already present') !== false, 'dry run after restore: nothing to do');
tx('DELETE FROM agent_review_holds WHERE id IN (?, ?)', [$SNAP[2]['id'], $SNAP[3]['id']]);   // a partly-restored table
$r = imp($EX, approve($EX));
ok($r['rc'] === 0 && strpos($r['out'], 'Applied: 2 hold(s) restored, 4 already present') !== false && holds() === $SNAP, 'partly-present table: only the missing rows are restored', $r['out']);

// ── H. Conflicts are refused, nothing overwritten ───────────────────────────
section('H. Conflicting existing records: refused, nothing changed');
$rid = $SNAP[1]['id'];
tx("UPDATE agent_review_holds SET resolution_note = 'edited after restore' WHERE id = ?", [$rid]);
$cur = holds();
$d = imp($EX); $r = imp($EX, approve($EX));
ok($d['rc'] !== 0 && strpos($d['out'], 'CONFLICT') !== false && $r['rc'] !== 0 && strpos($r['out'], "id $rid already exists with different content") !== false && holds() === $cur, 'same id, different content → refused, existing record kept as is', $r['out']);
tx('UPDATE agent_review_holds SET resolution_note = ? WHERE id = ?', [$SNAP[1]['resolution_note'], $rid]);
$new = hold_api(['action' => 'place', 'email' => 'imp.a2@test.invalid', 'reason_code' => 'other', 'note' => 'new hold after restore']);
ok(($new['hold_id'] ?? 0) > $MAXID, 'new holds get ids above every restored id (sequence advanced)', $new);
$cur = holds();
$r = imp($EX, approve($EX));
ok($r['rc'] !== 0 && strpos($r['out'], 'imp.a2@test.invalid already has hold row(s) not in this export') !== false && holds() === $cur, 'agent has a hold not in the export → refused for a person to decide', $r['out']);
// The dangerous real-world case: someone re-seeds from the CSV after re-applying, before importing.
$EX2 = export_to(DATA_DIR . '/imp-exports-2');   // (this export now includes the extra hold)
ok(reapply(), 're-applied');
sh('php ' . SEED . ' --db=' . DBF . ' --csv=' . $csv . ' --apply --by=' . ADMIN);
$cur = holds();
$r = imp($EX, approve($EX));
ok(count($cur) === 2 && $r['rc'] !== 0 && strpos($r['out'], 'CONFLICT') !== false && holds() === $cur, 're-seeded before import (would re-open a resolved review) → refused, nothing changed', $r['out']);
export_to(DATA_DIR . '/imp-exports-3');   // clear those 2 rows the safe way
ok(reapply() && nholds() === 0, 're-applied; table empty again');

// ── I. Interrupted imports apply nothing ────────────────────────────────────
section('I. Interrupted imports apply nothing; re-running completes');
$fourth = $SNAP[3]['id'];
tx("CREATE TRIGGER test_abort BEFORE INSERT ON agent_review_holds WHEN NEW.id = $fourth BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
$r = imp($EX, approve($EX));
ok($r['rc'] !== 0 && strpos($r['out'], 'Nothing applied (transaction rolled back)') !== false && nholds() === 0, 'failure on the 4th row → the first 3 are rolled back too', $r['out']);
tx('DROP TRIGGER test_abort');
// A real crash: kill -9 the process while its transaction is open (WAL mode, like production).
tx('CREATE TABLE test_slow (x INTEGER)');
tdb()->exec('WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < 3000) INSERT INTO test_slow SELECT x FROM c');
tx("CREATE TRIGGER test_hang BEFORE INSERT ON agent_review_holds WHEN NEW.id = $fourth BEGIN SELECT COUNT(*) FROM test_slow a, test_slow b, test_slow c; END");
$proc = proc_open(['php', IMP, '--db=' . DBF, '--export=' . $EX, '--apply', '--approved-by=' . ADMIN, '--expect-sha256=' . hash_file('sha256', $EX)],
                  [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(1500000);
$st = proc_get_status($proc);
$stillRunning = $st['running'];
posix_kill($st['pid'], 9);
$exit = proc_close($proc);
ok($stillRunning, 'import was mid-transaction (rows 1–3 inserted, stuck on row 4) when killed with SIGKILL');
ok(nholds() === 0 && tq1('PRAGMA quick_check') === 'ok', 'after the crash: no rows applied, database integrity ok');
tx('DROP TRIGGER test_hang'); tx('DROP TABLE test_slow');
$r = imp($EX, approve($EX));
ok($r['rc'] === 0 && holds() === $SNAP, 're-running after the crash restores everything exactly', $r['out']);
$r = imp($EX, approve($EX));
ok(strpos($r['out'], 'Applied: 0 hold(s) restored, 6 already present') !== false && holds() === $SNAP, '…and a further re-run adds nothing');

// ── J. Safety ───────────────────────────────────────────────────────────────
section('J. Safety');
$w = http_req(null, 'GET', '/scripts/review_holds_import.php');
ok($w['code'] === 404 && $w['body'] === '', 'not runnable over the web (CLI only)', $w['code']);
ok(!preg_match('/\b(require|include)(_once)?\b/', file_get_contents(IMP)), 'loads no app code (no auth.php / sessions)');
ok(tq('SELECT * FROM agent_intake ORDER BY email') === $intake0, 'no agent record modified');
ok(max_notif() === $notif0, 'no notification / email queued by anything in this suite');
foreach ([BK, WORK, DATA_DIR . '/imp-exports', DATA_DIR . '/imp-exports-2', DATA_DIR . '/imp-exports-3'] as $d) { fresh_dir($d); @rmdir($d); }
exit(summary());
