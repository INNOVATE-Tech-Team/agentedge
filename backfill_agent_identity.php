<?php
/**
 * One-time (safely re-runnable) backfill of the cross-system agent identity
 * map on innovate_roster: canonical_agent_id (Coastline), darwin_agent_person_id
 * (Darwin/AccountTECH), perfex_staff_id (Perfex) — see lib/agent_identity.php
 * for the matching rules.
 *
 * Usage:
 *   php backfill_agent_identity.php --dry-run   # compute + report only, no writes
 *   php backfill_agent_identity.php             # backup DB, then write matches
 *
 * Matching is exact-email only (primary innovate_roster.email, falling back to
 * agent_extra.alt_email only when primary has zero candidates). Never falls
 * back to agent name. An existing non-null ID column is never overwritten —
 * a disagreeing fresh match is recorded as a conflict for manual review, not
 * auto-resolved.
 */

define('AGENTEDGE_CRON', true);
chdir(__DIR__);
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/agent_identity.php';

$dryRun = in_array('--dry-run', $argv, true);

function fetch_json_ai(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

$pdo = local_db(); // ensures innovate_roster + new identity columns exist

echo "[" . date('Y-m-d H:i:s') . "] Agent identity backfill starting" . ($dryRun ? " (DRY RUN)" : "") . "\n";

// ── Build the three lookup indexes ──────────────────────────────────────────

// Coastline: GET /public/retention-roster?token= — same endpoint already
// used by api/roster.php and backoffice_roster.php. Authed (token) response
// includes email; 'id' is canonical_agents.id.
$c     = cfg();
$base  = rtrim($c['crm_base'] ?? '', '/');
$token = $c['crm_token'] ?? '';
$coastlineRows = [];
if ($base !== '' && $token !== '') {
    $url = $base . '/public/retention-roster?token=' . urlencode($token);
    $data = fetch_json_ai($url);
    if (is_array($data)) {
        foreach ($data as $a) {
            $coastlineRows[] = ['email' => $a['email'] ?? '', 'id' => $a['id'] ?? null];
        }
    }
}
if (empty($coastlineRows)) {
    echo "  WARNING: Coastline retention-roster returned no rows (unreachable, misconfigured, or empty) — canonical_agent_id matching will find nothing this run.\n";
}
$coastlineIndex = ai_build_index($coastlineRows, 'email', 'id');

// Darwin: already-synced local darwin_cap_progress (agent_email -> agent_person_id).
$darwinRows = $pdo->query("SELECT agent_email, agent_person_id FROM darwin_cap_progress")->fetchAll(PDO::FETCH_ASSOC);
$darwinIndex = ai_build_index($darwinRows, 'agent_email', 'agent_person_id');

// Perfex: direct read-only query against tblstaff (same connection api/roster.php uses).
$perfexRows = [];
try {
    $perfexRows = db_query("SELECT email, staffid FROM tblstaff", []);
} catch (\Exception $e) {
    echo "  WARNING: Perfex (tblstaff) query failed: " . $e->getMessage() . " — perfex_staff_id matching will find nothing this run.\n";
}
$perfexIndex = ai_build_index($perfexRows, 'email', 'staffid');

echo "  Indexes built: coastline=" . count($coastlineIndex) . " unique emails, darwin=" . count($darwinIndex) . ", perfex=" . count($perfexIndex) . "\n";

$indexes = ['coastline' => $coastlineIndex, 'darwin' => $darwinIndex, 'perfex' => $perfexIndex];

// ── agent_extra.alt_email, keyed by roster email ────────────────────────────
$altByEmail = [];
foreach ($pdo->query("SELECT email, alt_email FROM agent_extra WHERE alt_email != ''")->fetchAll(PDO::FETCH_ASSOC) as $ae) {
    $altByEmail[ai_norm_email($ae['email'])] = $ae['alt_email'];
}

// ── Load roster rows to evaluate (active agents) ────────────────────────────
$rosterRows = $pdo->query(
    "SELECT id, agent_name, email, canonical_agent_id, darwin_agent_person_id, perfex_staff_id
     FROM innovate_roster WHERE active = 1"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Evaluate every row ───────────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');
$stats = ['matched' => 0, 'partial' => 0, 'unmatched' => 0, 'conflict' => 0];
$newlyPopulated = ['canonical_agent_id' => [], 'darwin_agent_person_id' => [], 'perfex_staff_id' => []];
$conflicts = [];
$ambiguous = [];
$unmatchedReasons = []; // column => count of rows with zero candidates for that column
$results = []; // id => ['status'=>..., 'detail'=>...] for the write pass

foreach ($rosterRows as $row) {
    $altEmail = $altByEmail[ai_norm_email($row['email'])] ?? '';
    $rosterInput = ['email' => $row['email'], 'alt_email' => $altEmail];
    $existing = [
        'canonical_agent_id'     => $row['canonical_agent_id'],
        'darwin_agent_person_id' => $row['darwin_agent_person_id'],
        'perfex_staff_id'        => $row['perfex_staff_id'],
    ];

    $match = ai_match_roster_row($rosterInput, $existing, $indexes);
    $stats[$match['status']]++;

    $detail = [];
    foreach ($match['columns'] as $column => $c) {
        $resolved = $c['resolved'];
        $outcome  = $c['outcome'];
        $detail[$column] = ['resolved' => $resolved, 'outcome' => $outcome];

        if ($outcome['action'] === 'set') {
            $newlyPopulated[$column][] = ['roster_id' => $row['id'], 'agent_name' => $row['agent_name'], 'value' => $outcome['value'], 'source' => $resolved['source']];
        }
        if ($outcome['action'] === 'conflict') {
            $conflicts[] = ['roster_id' => $row['id'], 'agent_name' => $row['agent_name'], 'column' => $column, 'existing' => $outcome['existing'], 'fresh' => $outcome['fresh']];
        }
        if ($outcome['action'] === 'ambiguous') {
            $ambiguous[] = ['roster_id' => $row['id'], 'agent_name' => $row['agent_name'], 'column' => $column, 'candidates' => $resolved['candidates'] ?? [], 'source' => $resolved['source'] ?? null];
        }
        if ($outcome['action'] === 'none') {
            $unmatchedReasons[$column] = ($unmatchedReasons[$column] ?? 0) + 1;
        }
    }

    $results[$row['id']] = ['status' => $match['status'], 'detail' => $detail];
}

// ── Report (always printed, dry-run or not) ─────────────────────────────────
$total = count($rosterRows);
echo "\n=== Agent Identity Backfill Report" . ($dryRun ? " (DRY RUN — nothing written)" : "") . " ===\n";
echo "Total active roster agents evaluated: {$total}\n";
echo "  matched:   {$stats['matched']}\n";
echo "  partial:   {$stats['partial']}\n";
echo "  unmatched: {$stats['unmatched']}\n";
echo "  conflict:  {$stats['conflict']}\n";

foreach ($newlyPopulated as $column => $rows) {
    echo "\n-- {$column}: " . count($rows) . " would be newly populated --\n";
    foreach ($rows as $r) {
        echo "   roster_id={$r['roster_id']} ({$r['agent_name']}): {$r['value']} [via {$r['source']}]\n";
    }
}

echo "\n-- Existing-ID conflicts: " . count($conflicts) . " --\n";
foreach ($conflicts as $c) {
    echo "   roster_id={$c['roster_id']} ({$c['agent_name']}) {$c['column']}: existing={$c['existing']} fresh_match={$c['fresh']}\n";
}

echo "\n-- Ambiguous matches: " . count($ambiguous) . " --\n";
foreach ($ambiguous as $a) {
    echo "   roster_id={$a['roster_id']} ({$a['agent_name']}) {$a['column']} via {$a['source']}: candidates=" . implode(',', $a['candidates']) . "\n";
}

echo "\n-- Unmatched reasons (no candidate found), by column --\n";
foreach ($unmatchedReasons as $column => $count) {
    echo "   {$column}: {$count} rows with zero candidates\n";
}

if ($dryRun) {
    echo "\nDry run complete — no rows were modified.\n";
    exit(0);
}

// ── Write pass (only reached without --dry-run) ─────────────────────────────
$dataDir = rtrim((cfg()['local_db_dir'] ?? (__DIR__ . '/data')), '/');
$dbFile  = $dataDir . '/agentedge.db';
$backupFile = $dataDir . '/agentedge.db.bak-identity-backfill-' . date('Ymd_His');
if (file_exists($dbFile)) {
    if (!copy($dbFile, $backupFile)) {
        echo "ERROR: failed to create pre-write backup at {$backupFile} — aborting without writing.\n";
        exit(1);
    }
    echo "\nBackup written: {$backupFile}\n";
} else {
    echo "ERROR: expected DB file not found at {$dbFile} — aborting without writing.\n";
    exit(1);
}

$update = $pdo->prepare(
    "UPDATE innovate_roster
     SET canonical_agent_id     = COALESCE(:canonical, canonical_agent_id),
         darwin_agent_person_id = COALESCE(:darwin, darwin_agent_person_id),
         perfex_staff_id        = COALESCE(:perfex, perfex_staff_id),
         id_match_status        = :status,
         id_match_checked_at    = :checked_at,
         id_match_detail        = :detail
     WHERE id = :id"
);

$pdo->beginTransaction();
try {
    foreach ($results as $rosterId => $r) {
        $setValues = ['canonical' => null, 'darwin' => null, 'perfex' => null];
        $columnToParam = ['canonical_agent_id' => 'canonical', 'darwin_agent_person_id' => 'darwin', 'perfex_staff_id' => 'perfex'];
        foreach ($r['detail'] as $column => $c) {
            if ($c['outcome']['action'] === 'set') {
                $setValues[$columnToParam[$column]] = $c['outcome']['value'];
            }
        }
        $update->execute([
            ':canonical'   => $setValues['canonical'],
            ':darwin'      => $setValues['darwin'],
            ':perfex'      => $setValues['perfex'],
            ':status'      => $r['status'],
            ':checked_at'  => $now,
            ':detail'      => json_encode($r['detail']),
            ':id'          => $rosterId,
        ]);
    }
    $pdo->commit();
    echo "\nWrite pass complete: " . count($results) . " roster rows updated.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\nERROR during write pass, rolled back: " . $e->getMessage() . "\n";
    echo "Pre-write backup is intact at {$backupFile}.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Agent identity backfill done\n";
