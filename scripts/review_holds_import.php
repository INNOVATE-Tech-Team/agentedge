<?php
// Restores Needs Review holds from the JSON export written by a schema rollback:
//   php scripts/migrations/20261105_profile_status.php --rollback --force --export-dir=<dir>
// Use it only after the migration has been re-applied, to put the hold history
// back exactly as it was: same ids, reasons, notes, who placed it and when, and
// for resolved holds who resolved it, when, and the resolution note.
//
// CLI ONLY. Talks to the SQLite file directly; never loads auth.php, sessions
// or any app code, and never sends anything.
//
//   php review_holds_import.php --db=/path/agentedge.db --export=/path/rollback-export-....json
//       DRY RUN (default). Opens the database read-only, validates the export,
//       prints exactly what would be restored and the export's sha256.
//   php review_holds_import.php --db=... --export=... --apply \
//       --approved-by=<approver email> --expect-sha256=<sha256 printed by the dry run>
//       Restores. Both flags are required: the approval is tied to the exact
//       export file that was reviewed in the dry run.
//
// Guarantees:
//   - Validates the whole export first (shape, every field, timestamps, one
//     active hold per email). Any problem: nothing is applied.
//   - Restores rows verbatim, including their original ids. Resolved holds stay
//     resolved; nothing is ever re-opened, edited or deleted.
//   - Idempotent: a row already present with identical content is skipped, so
//     re-running restores nothing twice.
//   - Refuses (applies nothing) on any conflict: an id already used by a
//     different hold, or an agent in the export who already has hold rows that
//     are not part of the export (for example a hold placed or re-seeded after
//     the migration was re-applied). Those need a person to decide.
//   - --apply runs in ONE write-locked transaction and verifies every restored
//     row before committing: an interrupted or failed run applies nothing.
//   - Link expiry/revocation values in the export are reported, NOT restored.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

const MIG_ID      = '20261105_profile_status';
const HOLDS_INDEX = 'idx_agent_review_holds_one_active';
const REASONS     = ['duplicate', 'questionable_data', 'identity', 'other'];
const COLS        = ['id', 'email', 'reason_code', 'reason_note', 'source', 'placed_by', 'placed_at', 'resolved_at', 'resolved_by', 'resolution_note'];
const MAX_BYTES   = 20 * 1024 * 1024;

function say(string $s): void { echo $s, "\n"; }
function stop(string $s, int $rc = 1): void { fwrite(STDERR, "REFUSED: $s\nNothing applied.\n"); exit($rc); }

$o = getopt('', ['db:', 'export:', 'apply', 'approved-by:', 'expect-sha256:']);
$dbPath = $o['db'] ?? ''; $exPath = $o['export'] ?? '';
if ($dbPath === '' || !is_file($dbPath)) stop('--db=<existing agentedge.db> is required', 2);
if ($exPath === '' || !is_file($exPath)) stop('--export=<existing rollback export .json> is required', 2);
$apply = isset($o['apply']);
$by = strtolower(trim((string)($o['approved-by'] ?? '')));
$expect = strtolower(trim((string)($o['expect-sha256'] ?? '')));
if ($apply) {
    if (!filter_var($by, FILTER_VALIDATE_EMAIL)) stop('--apply requires --approved-by=<approver email> (explicit approval for this import)', 2);
    if (!preg_match('/^[0-9a-f]{64}$/', $expect)) stop('--apply requires --expect-sha256=<sha256 of the export, as printed by the dry run>', 2);
}

// ── 1. Read and validate the export (read once; hash the same bytes we parse) ──
$size = filesize($exPath);
if ($size === false || $size === 0) stop('export file is empty');
if ($size > MAX_BYTES) stop("export file is larger than " . MAX_BYTES . " bytes — not a holds export");
$raw = file_get_contents($exPath);
if ($raw === false || strlen($raw) !== $size) stop('could not read the whole export file');
$sha = hash('sha256', $raw);
say("== Review-hold import — " . ($apply ? 'APPLY' : 'DRY RUN (read-only)') . " — db: $dbPath");
say("export: $exPath");
say("export sha256: $sha");
if ($apply && !hash_equals($expect, $sha)) stop("export sha256 is $sha, not the approved $expect — the file changed since the dry run");

function valid_ts($v): bool {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return false;
    $d = DateTime::createFromFormat('!Y-m-d H:i:s', $v, new DateTimeZone('UTC'));
    return $d !== false && $d->format('Y-m-d H:i:s') === $v;
}
function nonempty_str($v): bool { return is_string($v) && trim($v) !== ''; }

$doc = json_decode($raw, true, 64);
if (!is_array($doc)) stop('export is not valid JSON (' . json_last_error_msg() . ')');
foreach (['migration', 'exported_at_utc', 'agent_review_holds', 'profile_completion_tokens_new_columns'] as $k)
    if (!array_key_exists($k, $doc)) stop("export is missing \"$k\" — not a rollback export of " . MIG_ID);
if ($doc['migration'] !== MIG_ID) stop('export is from migration ' . json_encode($doc['migration']) . ', expected ' . MIG_ID);
if (!is_array($doc['agent_review_holds']) || !array_is_list($doc['agent_review_holds'])) stop('"agent_review_holds" must be a list');
if (!is_array($doc['profile_completion_tokens_new_columns']) || !array_is_list($doc['profile_completion_tokens_new_columns'])) stop('"profile_completion_tokens_new_columns" must be a list');
say('exported at (UTC): ' . (is_string($doc['exported_at_utc']) ? $doc['exported_at_utc'] : '?') . '; source db: ' . (is_string($doc['db'] ?? null) ? $doc['db'] : '?'));

$errors = []; $rows = []; $activeByEmail = [];
foreach ($doc['agent_review_holds'] as $i => $r) {
    $n = '#' . ($i + 1);
    if (!is_array($r) || array_is_list($r)) { $errors[] = "$n: not a hold record"; continue; }
    $keys = array_keys($r); $want = COLS; sort($keys); sort($want);
    if ($keys !== $want) { $errors[] = "$n: columns must be exactly " . implode(',', COLS) . ' (got ' . implode(',', array_keys($r)) . ')'; continue; }
    $id = $r['id'];
    if (is_string($id) && ctype_digit($id)) $id = (int)$id;
    if (!is_int($id) || $id < 1) { $errors[] = "$n: id must be a positive integer"; continue; }
    $e = []; $t = "$n (id $id)";
    if (!is_string($r['email']) || $r['email'] !== trim($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $e[] = "$t: invalid email";
    if (!in_array($r['reason_code'], REASONS, true)) $e[] = "$t: unknown reason_code " . json_encode($r['reason_code']);
    if (!is_string($r['reason_note'])) $e[] = "$t: reason_note must be text";
    if (!is_string($r['source']) || !($r['source'] === 'staff' || preg_match('/^seed:.+$/', $r['source']))) $e[] = "$t: source must be 'staff' or 'seed:<file>'";
    if (!nonempty_str($r['placed_by'])) $e[] = "$t: placed_by is missing";
    if (!valid_ts($r['placed_at'])) $e[] = "$t: placed_at is not a valid 'YYYY-MM-DD HH:MM:SS' timestamp";
    $resolvedParts = [$r['resolved_at'] !== null, $r['resolved_by'] !== null, $r['resolution_note'] !== null];
    if (in_array(true, $resolvedParts, true)) {
        if (!valid_ts($r['resolved_at'])) $e[] = "$t: resolved_at is not a valid timestamp";
        elseif (valid_ts($r['placed_at']) && strcmp($r['resolved_at'], $r['placed_at']) < 0) $e[] = "$t: resolved_at is before placed_at";
        if (!nonempty_str($r['resolved_by'])) $e[] = "$t: resolved hold has no resolved_by";
        if (!nonempty_str($r['resolution_note'])) $e[] = "$t: resolved hold has no resolution note";
    }
    if (isset($rows[$id])) $e[] = "$t: duplicate id in export";
    if ($e) { array_push($errors, ...$e); continue; }
    $r['id'] = $id;
    $rows[$id] = $r;
    if ($r['resolved_at'] === null) {
        if (isset($activeByEmail[$r['email']])) $errors[] = "$t: second ACTIVE hold for the same agent (also id {$activeByEmail[$r['email']]})";
        else $activeByEmail[$r['email']] = $id;
    }
}
if ($errors) { fwrite(STDERR, "Export failed validation:\n  - " . implode("\n  - ", $errors) . "\n"); stop('invalid export'); }
ksort($rows);
$nActive = count($activeByEmail); $nResolved = count($rows) - $nActive;
say('export is valid: ' . count($rows) . " hold record(s) — $nActive active, $nResolved resolved");
$nLinks = count($doc['profile_completion_tokens_new_columns']);
if ($nLinks) say("note: $nLinks link expiry/revocation record(s) in this export are NOT restored by this script (see the runbook).");

// ── 2. Database checks ──
$flags = $apply ? [] : [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
try { $p = new PDO('sqlite:' . $dbPath, null, null, $flags); }
catch (\Throwable $e) { stop('cannot open database: ' . $e->getMessage()); }
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA busy_timeout=15000');
if ($p->query('PRAGMA quick_check')->fetchColumn() !== 'ok') stop('database quick_check is not ok');
if (!$p->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='agent_review_holds'")->fetchColumn())
    stop('agent_review_holds does not exist — re-apply migration ' . MIG_ID . ' first');
$tcols = array_column($p->query('PRAGMA table_info(agent_review_holds)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if ($tcols !== COLS) stop('agent_review_holds columns are not the ones this script restores: ' . implode(',', $tcols));
$idx = $p->query("SELECT sql FROM sqlite_master WHERE type='index' AND name='" . HOLDS_INDEX . "'")->fetchColumn();
if (!$idx || stripos($idx, 'UNIQUE') === false) stop('the one-active-hold unique index is missing — re-apply migration ' . MIG_ID . ' first');

function norm(array $r): array { $x = []; foreach (COLS as $c) $x[$c] = $c === 'id' ? (int)$r[$c] : ($r[$c] === null ? null : (string)$r[$c]); return $x; }

// Decide, row by row, what to do. Run inside the write lock when applying.
function plan(PDO $p, array $rows): array {
    $byId = $p->prepare('SELECT * FROM agent_review_holds WHERE id = ?');
    $byEmail = $p->prepare('SELECT id FROM agent_review_holds WHERE email = ? ORDER BY id');
    $restore = []; $present = []; $conflicts = []; $seen = [];
    foreach ($rows as $id => $r) {
        $byId->execute([$id]);
        if (($cur = $byId->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (norm($cur) === norm($r)) $present[] = $id;
            else $conflicts[] = "id $id already exists with different content (db: {$cur['email']}, " . ($cur['resolved_at'] === null ? 'active' : 'resolved') . "; export: {$r['email']}, " . ($r['resolved_at'] === null ? 'active' : 'resolved') . ')';
        } else {
            $restore[] = $id;
        }
        if (!isset($seen[$r['email']])) {
            $seen[$r['email']] = true;
            $byEmail->execute([$r['email']]);
            $foreign = array_values(array_filter(array_map('intval', $byEmail->fetchAll(PDO::FETCH_COLUMN)), fn($x) => !isset($rows[$x])));
            if ($foreign) $conflicts[] = "{$r['email']} already has hold row(s) not in this export (id " . implode(', ', $foreign) . ') — placed or re-seeded after the migration was re-applied; decide manually';
        }
    }
    return [$restore, $present, $conflicts];
}
function show(array $rows, array $restore, array $present, array $conflicts, bool $apply): void {
    foreach ($restore as $id) { $r = $rows[$id];
        say(sprintf('  %s id %-5d %-40s %-17s %s  placed %s by %s%s', $apply ? 'RESTORE      ' : 'WOULD RESTORE', $id, $r['email'], $r['reason_code'],
            $r['resolved_at'] === null ? 'ACTIVE  ' : 'resolved', $r['placed_at'], $r['placed_by'], $r['resolved_at'] === null ? '' : "; resolved {$r['resolved_at']} by {$r['resolved_by']}")); }
    foreach ($present as $id) say(sprintf('  skip (already restored, identical) id %d %s', $id, $rows[$id]['email']));
    foreach ($conflicts as $c) say("  CONFLICT  $c");
}

// ── 3. Dry run ──
if (!$apply) {
    [$restore, $present, $conflicts] = plan($p, $rows);
    show($rows, $restore, $present, $conflicts, false);
    if ($conflicts) { say('DRY RUN: ' . count($conflicts) . ' conflict(s) — an import would be REFUSED. Nothing changed.'); exit(1); }
    say('DRY RUN: ' . count($restore) . ' hold(s) would be restored, ' . count($present) . ' already present. Nothing changed.');
    say("To apply (needs explicit approval): --apply --approved-by=<approver email> --expect-sha256=$sha");
    exit(0);
}

// ── 4. Apply: one write-locked transaction, verified before commit ──
$p->exec('BEGIN IMMEDIATE');
try {
    [$restore, $present, $conflicts] = plan($p, $rows);
    show($rows, $restore, $present, $conflicts, true);
    if ($conflicts) throw new RuntimeException(count($conflicts) . ' conflict(s) with existing records');
    $before = (int)$p->query('SELECT COUNT(*) FROM agent_review_holds')->fetchColumn();
    $ins = $p->prepare('INSERT INTO agent_review_holds (' . implode(', ', COLS) . ') VALUES (' . implode(', ', array_fill(0, count(COLS), '?')) . ')');
    foreach ($restore as $id) { $r = $rows[$id]; $ins->execute(array_map(fn($c) => $r[$c], COLS)); }
    $chk = $p->prepare('SELECT * FROM agent_review_holds WHERE id = ?');
    foreach ($rows as $id => $r) {
        $chk->execute([$id]); $cur = $chk->fetch(PDO::FETCH_ASSOC);
        if ($cur === false || norm($cur) !== norm($r)) throw new RuntimeException("verify failed for id $id");
    }
    if ((int)$p->query('SELECT COUNT(*) FROM agent_review_holds')->fetchColumn() !== $before + count($restore)) throw new RuntimeException('verify failed: unexpected row count');
    $p->exec('COMMIT');
} catch (\Throwable $e) {
    try { $p->exec('ROLLBACK'); } catch (\Throwable $e2) {}
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\nNothing applied (transaction rolled back).\n");
    exit(1);
}
if ($p->query('PRAGMA quick_check')->fetchColumn() !== 'ok') { fwrite(STDERR, "WARNING: quick_check after import is not ok — investigate\n"); exit(1); }
say('Applied: ' . count($restore) . ' hold(s) restored, ' . count($present) . ' already present. Every row verified. Approved by ' . $by . '; export sha256 ' . $sha . '.');
exit(0);
