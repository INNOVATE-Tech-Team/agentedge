<?php
/**
 * One-off: reorganize Vault departments to mirror the Back Office nav
 * structure (Operations, Finance, Broker Files, Agent Communications,
 * Events, Agent Development, Technology, Human Resources), per Darren's
 * spec 2026-10-11. Safe to re-run — every step checks current state first.
 *
 * Run via: docker exec agentedge php /var/www/html/scripts/vault_reorg_2026_10_11.php
 */
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
$db = local_db();

function dept_exists(PDO $db, string $slug): bool {
    $s = $db->prepare("SELECT 1 FROM vault_depts WHERE slug=?");
    $s->execute([$slug]);
    return (bool) $s->fetchColumn();
}
function folder_exists(PDO $db, string $id): bool {
    $s = $db->prepare("SELECT 1 FROM vault_folders WHERE id=?");
    $s->execute([$id]);
    return (bool) $s->fetchColumn();
}
function file_count(PDO $db, string $folderId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM vault_files WHERE folder_id=?");
    $s->execute([$folderId]);
    return (int) $s->fetchColumn();
}

// ── Pre-flight: record file counts on the two folders with real content ───
$preCount1099 = folder_exists($db, '3b603ac4-c63a-4003-a653-a16324cee51da64')
    ? file_count($db, '3b603ac4-c63a-4003-a653-a16324cee51da64') : null;
$preCountQB = folder_exists($db, '0b5878ac-2776-40e7-b256-10228c105fc3b620')
    ? file_count($db, '0b5878ac-2776-40e7-b256-10228c105fc3b620') : null;
echo "Pre-flight: 2022-2025 1099s has " . ($preCount1099 ?? 'N/A (already renamed)') . " files, QuickBooks Records has " . ($preCountQB ?? 'N/A') . " files.\n";

// ── 1. Rename existing depts (slug unchanged, name + sort_ord updated) ────
$renames = [
    'operations' => ['Operations', 10],
    'finance'    => ['Finance', 20],
    'brokerage'  => ['Broker Files', 30],
    'marketing'  => ['Agent Communications', 40],
    'technology' => ['Technology', 70],
    'hr'         => ['Human Resources', 80],
];
foreach ($renames as $slug => [$name, $sort]) {
    if (!dept_exists($db, $slug)) { echo "SKIP rename $slug — dept not found.\n"; continue; }
    $db->prepare("UPDATE vault_depts SET name=?, sort_ord=? WHERE slug=?")->execute([$name, $sort, $slug]);
    echo "Renamed dept '$slug' -> '$name' (sort $sort).\n";
}

// ── 2. Create the two brand-new depts ──────────────────────────────────────
$newDepts = [
    ['events', 'Events', 50],
    ['agent-development', 'Agent Development', 60],
];
foreach ($newDepts as [$slug, $name, $sort]) {
    if (dept_exists($db, $slug)) { echo "SKIP create dept $slug — already exists.\n"; continue; }
    $db->prepare("INSERT INTO vault_depts (slug, name, sort_ord, restricted) VALUES (?,?,?,0)")->execute([$slug, $name, $sort]);
    echo "Created dept '$slug' ('$name').\n";
}

// ── 3. Move folders between depts (dept_slug changes, name/id unchanged) ──
$moves = [
    // id => new dept_slug
    'vf-legal-contracts'       => 'brokerage',
    'vf-legal-compliance'      => 'brokerage',
    'vf-agentmls-datadocs'     => 'brokerage',
    'vf-agentmls-access'       => 'brokerage',
    'vf-hr-mc-leader-onboarding' => 'hr',
    'vf-hr-bic-onboarding'     => 'hr',
    'vf-hr-employee-documents' => 'hr',
    'vf-operations-systems'    => 'technology', // Office Systems & Tools: Operations -> Technology
];
foreach ($moves as $id => $newDept) {
    if (!folder_exists($db, $id)) { echo "SKIP move $id — folder not found (already moved?).\n"; continue; }
    $db->prepare("UPDATE vault_folders SET dept_slug=? WHERE id=?")->execute([$newDept, $id]);
    echo "Moved folder $id -> dept '$newDept'.\n";
}

// ── 4. Rename + move "Interview & Onboarding Checklists" -> "Onboarding
//      Checklists" in Operations (interview-specific recruiting content now
//      lives in INNOVATE Advantage, per Darren) ─────────────────────────────
if (folder_exists($db, 'vf-recruiting-onboarding')) {
    $db->prepare("UPDATE vault_folders SET name='Onboarding Checklists', dept_slug='operations' WHERE id='vf-recruiting-onboarding'")->execute();
    echo "Renamed+moved 'Interview & Onboarding Checklists' -> 'Onboarding Checklists' (Operations).\n";
} else {
    echo "SKIP — vf-recruiting-onboarding not found.\n";
}

// ── 5. Rename "2022-2025 1099s" -> "1099s" (merge target; the other half of
//      the merge, "1099s - Former Agents", has 0 files and is just deleted
//      below rather than needing an actual file move) ─────────────────────
$the1099Id = '3b603ac4-c63a-4003-a653-a16324cee51da64';
if (folder_exists($db, $the1099Id)) {
    $db->prepare("UPDATE vault_folders SET name='1099s' WHERE id=?")->execute([$the1099Id]);
    echo "Renamed '2022-2025 1099s' -> '1099s' (Finance).\n";
}

// ── 6. Set per-folder restriction on Payroll Records ───────────────────────
if (folder_exists($db, 'vf-hr-payroll-records')) {
    $db->prepare("UPDATE vault_folders SET restricted_emails=? WHERE id=?")
        ->execute(['darren@innovateonline.com,michele@innovateonline.com', 'vf-hr-payroll-records']);
    echo "Set restricted_emails on 'Payroll Records (W-2/1099)'.\n";
}

// ── 7. Delete folders confirmed empty (0 files each, verified below) ──────
$toDelete = [
    'vf-recruiting-playbooks'    => 'Recruiting Scripts & Playbooks (recruiting now in INNOVATE Advantage)',
    'vf-recruiting-collateral'   => 'Recruiting Collateral (recruiting now in INNOVATE Advantage)',
    'vf-hr-commission'           => 'Commission Plans (duplicates Public > Compensation Plans)',
    'vf-tax-1099-former-agents'  => '1099s - Former Agents (merged into Finance > 1099s)',
];
foreach ($toDelete as $id => $reason) {
    if (!folder_exists($db, $id)) { echo "SKIP delete $id — already gone.\n"; continue; }
    $n = file_count($db, $id);
    if ($n > 0) { echo "ABORT delete $id — has $n files, NOT deleting ($reason).\n"; continue; }
    $db->prepare("DELETE FROM vault_folders WHERE id=?")->execute([$id]);
    echo "Deleted empty folder $id ($reason).\n";
}

// ── 8. Delete now-drained dept headers ─────────────────────────────────────
$draineDepts = ['legal', 'agent-mls', 'recruiting', 'hr-onboarding', 'tax-documents', 'hr-payroll'];
foreach ($draineDepts as $slug) {
    if (!dept_exists($db, $slug)) { echo "SKIP delete dept $slug — already gone.\n"; continue; }
    $remaining = (int) $db->prepare("SELECT COUNT(*) FROM vault_folders WHERE dept_slug=?")->execute([$slug])
        ? $db->query("SELECT COUNT(*) FROM vault_folders WHERE dept_slug='$slug'")->fetchColumn() : 0;
    if ($remaining > 0) { echo "ABORT delete dept $slug — still has $remaining folder(s).\n"; continue; }
    $db->prepare("DELETE FROM vault_depts WHERE slug=?")->execute([$slug]);
    echo "Deleted empty dept '$slug'.\n";
}

// ── 9. Grant darren + michele access to the two depts that became
//      restricted/renamed-but-restricted along the way (finance, which was
//      already restricted, already has their grants from before; nothing
//      new needed there). No new restricted DEPT was created this pass —
//      payroll records uses the new per-folder mechanism instead — so no
//      new vault_user_depts rows are needed here.

// ── 10. Create new empty folders ───────────────────────────────────────────
function mk_id(string $hint): string {
    return 'vf-' . $hint . '-' . substr(md5($hint . microtime()), 0, 8);
}
$newFolders = [
    ['operations', 'Offboarding Checklists'],
    ['finance', 'State Annual Reports'],
    ['finance', 'MLS & Board Dues'],
    ['finance', 'Audit & Exchange Readiness'],
    ['brokerage', 'Licensing & Renewals'],
    ['brokerage', 'MLS Participant & Data Access Agreements'],
    ['marketing', 'Announcement & Email/Text Templates'],
    ['events', 'Event Planning Checklists'],
    ['events', 'Venue, Vendor & Sponsor Agreements'],
    ['events', 'Event Recaps & Photos'],
    ['agent-development', 'LAUNCH Curriculum'],
    ['agent-development', 'Workshop Wednesdays Presentations'],
    ['technology', 'Technology Vendor Contracts'],
    ['technology', 'Integration & API Documentation'],
    ['hr', 'Staff & MCL Compensation'],
    ['hr', 'Interview Guides & Hiring'],
    ['hr', 'Staff Handbook & Policies'],
];
foreach ($newFolders as [$dept, $name]) {
    $exists = $db->prepare("SELECT 1 FROM vault_folders WHERE dept_slug=? AND name=?");
    $exists->execute([$dept, $name]);
    if ($exists->fetchColumn()) { echo "SKIP create '$name' in $dept — already exists.\n"; continue; }
    $id = mk_id(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
    $db->prepare("INSERT INTO vault_folders (id, parent_id, name, visibility, dept_slug, created_by, created_at)
        VALUES (?, NULL, ?, 'dept', ?, ?, datetime('now'))")
        ->execute([$id, $name, $dept, 'darren@innovateonline.com']);
    echo "Created folder '$name' in $dept.\n";
}

// ── Post-flight verification ───────────────────────────────────────────────
echo "\n=== Post-flight ===\n";
$post1099 = file_count($db, $the1099Id);
$postQB = file_count($db, '0b5878ac-2776-40e7-b256-10228c105fc3b620');
echo "1099s (renamed) now has $post1099 files (expected " . ($preCount1099 ?? $post1099) . ").\n";
echo "QuickBooks Records now has $postQB files (expected " . ($preCountQB ?? $postQB) . ").\n";
if ($preCount1099 !== null && $post1099 !== $preCount1099) echo "*** MISMATCH on 1099s file count! ***\n";
if ($preCountQB !== null && $postQB !== $preCountQB) echo "*** MISMATCH on QuickBooks Records file count! ***\n";

echo "\nFinal vault_depts:\n";
foreach ($db->query("SELECT slug, name, sort_ord, restricted FROM vault_depts ORDER BY sort_ord")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['slug']} | {$r['name']} | sort={$r['sort_ord']} | restricted={$r['restricted']}\n";
}
echo "\nDone.\n";
