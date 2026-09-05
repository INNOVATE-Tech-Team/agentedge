<?php
// Merge two duplicate AgentEdge agent_intake records into one.
//
// GET  ?a=email1&b=email2  → admin only: preview. Returns both agent_intake
//      rows plus, for every other local table with an email/agent_email/
//      from_email column, how many rows exist under each address — so the
//      UI can show what will move and flag any table where BOTH sides
//      already have a row (a "singleton" table — the loser's row there gets
//      discarded, not silently overwritten).
// POST { survivor, duplicate } → admin only: performs the merge.
//
// The table list isn't hardcoded — it's discovered from the live schema each
// time (see merge_discover_targets()), the same way this bug was caused by
// one intake code path not knowing about a link (agent_extra.alt_email)
// another part of the app had already recorded. New tables added later with
// an email/agent_email/from_email column are picked up automatically.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }

$pdo = local_db();
$adminEmail = strtolower(trim($agent['email'] ?? ''));

// Tables that are frozen snapshots or bookkeeping, never a merge target.
const MERGE_SKIP_TABLES = ['agent_intake', 'local_db_schema_meta'];
const MERGE_COLS = ['email', 'agent_email', 'from_email'];

// Every (table, column) elsewhere in the local DB that identifies an agent
// by email, tagged with whether that column is uniquely constrained (PK or
// a single-column UNIQUE index) — i.e. at most one row per agent, so a
// merge either moves the loser's row in (survivor has none) or discards it
// (survivor already has its own). Non-singleton = an append-style log/join
// table, safe to bulk-reassign every matching row.
function merge_discover_targets(PDO $pdo): array {
    $out = [];
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (in_array($t, MERGE_SKIP_TABLES, true)) continue;
        if (preg_match('/_backup_|_before_revert_/i', $t)) continue;
        $info = $pdo->query("PRAGMA table_info(\"$t\")")->fetchAll(PDO::FETCH_ASSOC);
        $pkCols = array_values(array_filter($info, fn($c) => (int)$c['pk'] > 0));
        foreach ($info as $c) {
            if (!in_array($c['name'], MERGE_COLS, true)) continue;
            $singleton = (count($pkCols) === 1 && $pkCols[0]['name'] === $c['name']);
            if (!$singleton) {
                foreach ($pdo->query("PRAGMA index_list(\"$t\")")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                    if (!$ix['unique']) continue;
                    $ixInfo = $pdo->query("PRAGMA index_info(\"{$ix['name']}\")")->fetchAll(PDO::FETCH_ASSOC);
                    if (count($ixInfo) === 1 && $ixInfo[0]['name'] === $c['name']) { $singleton = true; break; }
                }
            }
            $out[] = ['table' => $t, 'col' => $c['name'], 'singleton' => $singleton];
        }
    }
    return $out;
}

// TEXT columns on agent_intake itself that are safe to fill-forward onto the
// survivor when blank there but present on the loser (license #, address,
// bio, etc). Excludes email/submitted*/updated_at (identity + bookkeeping).
function merge_fillable_intake_cols(PDO $pdo): array {
    $info = $pdo->query("PRAGMA table_info(agent_intake)")->fetchAll(PDO::FETCH_ASSOC);
    $skip = ['email', 'submitted', 'submitted_at', 'updated_at'];
    $out = [];
    foreach ($info as $c) {
        if ($c['type'] !== 'TEXT' || in_array($c['name'], $skip, true)) continue;
        $out[] = $c['name'];
    }
    return $out;
}

function norm_email($e): string { return strtolower(trim((string)$e)); }
// agent_intake.email is a case-sensitive TEXT PRIMARY KEY, so two rows can exist
// for the very same address in different case (the intake bug this tool cleans
// up after). Identity for THAT table must use the exact string, never lower() —
// every other table already treats email case-insensitively as one identity, so
// lower() stays correct there.
function raw_email($e): string { return trim((string)$e); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $a = raw_email($_GET['a'] ?? '');
    $b = raw_email($_GET['b'] ?? '');
    if ($a === '' || $b === '' || $a === $b) {
        http_response_code(400); echo json_encode(['error' => 'two distinct emails required']); exit;
    }
    $rowA = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?"); $rowA->execute([$a]);
    $rowB = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?"); $rowB->execute([$b]);
    $rowA = $rowA->fetch(PDO::FETCH_ASSOC); $rowB = $rowB->fetch(PDO::FETCH_ASSOC);
    if (!$rowA || !$rowB) { http_response_code(404); echo json_encode(['error' => 'one or both agents not found']); exit; }

    // Same email, different case only — every other table already keys off one
    // case-insensitive identity, so there's nothing there to reassign; only the
    // duplicate agent_intake row itself goes away.
    $sameIdentity = norm_email($a) === norm_email($b);
    $tableInfo = [];
    if (!$sameIdentity) {
        $targets = merge_discover_targets($pdo);
        foreach ($targets as $tgt) {
            $t = $tgt['table']; $col = $tgt['col'];
            $cA = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\" WHERE lower(\"$col\")=" . $pdo->quote(norm_email($a)))->fetchColumn();
            $cB = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\" WHERE lower(\"$col\")=" . $pdo->quote(norm_email($b)))->fetchColumn();
            if ($cA === 0 && $cB === 0) continue;
            $tableInfo[] = [
                'table' => $t, 'col' => $col, 'singleton' => $tgt['singleton'],
                'count_a' => $cA, 'count_b' => $cB,
                'conflict' => $tgt['singleton'] && $cA > 0 && $cB > 0,
            ];
        }
    }

    echo json_encode(['ok' => true, 'a' => $rowA, 'b' => $rowB, 'tables' => $tableInfo]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$survivor  = raw_email($body['survivor'] ?? '');
$duplicate = raw_email($body['duplicate'] ?? '');
if ($survivor === '' || $duplicate === '' || $survivor === $duplicate) {
    http_response_code(400); echo json_encode(['error' => 'survivor and duplicate emails (distinct) required']); exit;
}

$survStmt = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?"); $survStmt->execute([$survivor]);
$survRow = $survStmt->fetch(PDO::FETCH_ASSOC);
$dupStmt = $pdo->prepare("SELECT * FROM agent_intake WHERE email=?"); $dupStmt->execute([$duplicate]);
$dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);
if (!$survRow || !$dupRow) { http_response_code(404); echo json_encode(['error' => 'one or both agents not found']); exit; }

// Same email, different case only — nothing in any other table needs to move
// (see the GET handler above); skip straight to dropping the duplicate row.
$sameIdentity = norm_email($survivor) === norm_email($duplicate);

$pdo->beginTransaction();
try {
    // 1) Fill blank fields on the survivor's own intake row from the duplicate.
    $filled = [];
    $fillCols = merge_fillable_intake_cols($pdo);
    $setParts = []; $params = [];
    foreach ($fillCols as $col) {
        $survVal = trim((string)($survRow[$col] ?? ''));
        $dupVal  = trim((string)($dupRow[$col] ?? ''));
        if ($survVal === '' && $dupVal !== '') {
            $setParts[] = "\"$col\"=?";
            $params[] = $dupVal;
            $filled[] = $col;
        }
    }
    if ($setParts) {
        $params[] = $survivor;
        $pdo->prepare("UPDATE agent_intake SET " . implode(',', $setParts) . " WHERE email=?")->execute($params);
    }

    // 2) Reassign or discard every other table that keys off email. Skipped for a
    // same-email/different-case pair — see $sameIdentity above.
    $moved = []; $discarded = [];
    if (!$sameIdentity) {
        $survNorm = norm_email($survivor); $dupNorm = norm_email($duplicate);
        foreach (merge_discover_targets($pdo) as $tgt) {
            $t = $tgt['table']; $col = $tgt['col'];
            if ($tgt['singleton']) {
                $survHas = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\" WHERE lower(\"$col\")=" . $pdo->quote($survNorm))->fetchColumn();
                if ($survHas > 0) {
                    $n = $pdo->exec("DELETE FROM \"$t\" WHERE lower(\"$col\")=" . $pdo->quote($dupNorm));
                    if ($n) $discarded[] = "$t ($n)";
                    continue;
                }
            }
            $st = $pdo->prepare("UPDATE \"$t\" SET \"$col\"=? WHERE lower(\"$col\")=?");
            $st->execute([$survNorm, $dupNorm]);
            if ($st->rowCount() > 0) $moved[] = "$t ({$st->rowCount()})";
        }
    }

    // 3) Drop the now-empty duplicate intake row — the exact row only (see the
    // case-sensitive note on raw_email() above for why lower() must not be used here).
    $pdo->prepare("DELETE FROM agent_intake WHERE email=?")->execute([$duplicate]);

    // 4) Audit trail, visible on the surviving agent's profile.
    $note = "Merged duplicate AgentEdge record $duplicate into this profile.";
    if ($filled)    $note .= " Filled in from duplicate: " . implode(', ', $filled) . ".";
    if ($moved)     $note .= " Moved: " . implode(', ', $moved) . ".";
    if ($discarded) $note .= " Discarded (this profile already had its own row): " . implode(', ', $discarded) . ".";
    if ($sameIdentity) $note .= " (Same email, different case — no other tables needed changes.)";
    $pdo->prepare("INSERT INTO agent_notes (email, note, created_by) VALUES (?,?,?)")
        ->execute([$survivor, $note, $adminEmail ?: 'admin']);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'merge failed, nothing was changed: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'survivor' => $survivor, 'filled' => $filled, 'moved' => $moved, 'discarded' => $discarded]);
