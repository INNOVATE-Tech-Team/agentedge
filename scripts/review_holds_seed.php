<?php
// Seeds Needs Review holds from a reviewed CSV (kept OUTSIDE the repo — the
// application contains no list of agents). Dry run by default.
// CLI only; talks to the SQLite file directly (no app/session code).
//
//   php review_holds_seed.php --db=/path/agentedge.db --csv=/path/holds.csv            # dry run
//   php review_holds_seed.php --db=... --csv=... --by=abril@innovateonline.com --apply
//
// CSV header: email,reason_code,note   (reason_code: duplicate | questionable_data | identity | other)
// Never modifies agent records; only inserts hold rows. Skips anyone who
// already has an active hold, so re-running is safe.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
const REASONS = ['duplicate', 'questionable_data', 'identity', 'other'];

$o = getopt('', ['db:', 'csv:', 'by:', 'apply']);
foreach (['db', 'csv'] as $k) if (empty($o[$k]) || !is_file($o[$k])) { fwrite(STDERR, "--$k=<existing file> required\n"); exit(2); }
$apply = isset($o['apply']);
$by = strtolower(trim($o['by'] ?? ''));
if ($apply && !filter_var($by, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "--by=<your email> required with --apply\n"); exit(2); }

$p = new PDO('sqlite:' . $o['db']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA busy_timeout=15000');
if (!$p->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='agent_review_holds'")->fetchColumn()) {
    fwrite(STDERR, "agent_review_holds does not exist — apply migration 20261105_profile_status first\n"); exit(1);
}

$fh = fopen($o['csv'], 'r');
$head = array_map('trim', fgetcsv($fh) ?: []);
if (array_slice($head, 0, 3) !== ['email', 'reason_code', 'note']) { fwrite(STDERR, "CSV header must be: email,reason_code,note\n"); exit(2); }
$rows = []; $errors = [];
while (($r = fgetcsv($fh)) !== false) {
    if (!array_filter($r)) continue;
    [$email, $reason, $note] = array_map('trim', array_pad($r, 3, ''));
    $email = strtolower($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "bad email: $email"; continue; }
    if (!in_array($reason, REASONS, true)) { $errors[] = "bad reason for $email: $reason"; continue; }
    $rows[$email] = [$reason, $note];
}
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\nNothing applied.\n"); exit(1); }

$active = $p->prepare("SELECT 1 FROM agent_review_holds WHERE email=? AND resolved_at IS NULL");
$known  = $p->prepare("SELECT 1 FROM agent_intake WHERE email=?");
$ins    = $p->prepare("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by) VALUES (?, ?, ?, ?, ?)");
$todo = 0; $skip = 0; $unknown = 0;
if ($apply) $p->beginTransaction();
foreach ($rows as $email => [$reason, $note]) {
    $active->execute([$email]);
    if ($active->fetchColumn()) { $skip++; echo "  skip (already held)  $email\n"; continue; }
    $known->execute([$email]);
    if (!$known->fetchColumn()) { $unknown++; echo "  note: no agent_intake row for $email (hold still recorded)\n"; }
    $todo++;
    echo ($apply ? '  HOLD  ' : '  WOULD HOLD  ') . "$email  [$reason]" . ($note !== '' ? "  $note" : '') . "\n";
    if ($apply) $ins->execute([$email, $reason, $note, 'seed:' . basename($o['csv']), $by]);
}
if ($apply) $p->commit();
echo ($apply ? 'Applied' : 'Dry run') . ": $todo hold(s)" . ($apply ? ' placed' : ' would be placed') . ", $skip already held, $unknown without a profile row.\n";
