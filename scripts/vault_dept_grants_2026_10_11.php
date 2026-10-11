<?php
/**
 * One-off: follow-up access/structure changes after the main Vault reorg
 * (scripts/vault_reorg_2026_10_11.php), per Darren:
 *   - Human Resources: restrict the whole department to darren/michele
 *     (not just the Payroll Records folder within it).
 *   - Compliance & Risk / Contract Templates (now in Broker Files): left
 *     open, confirmed fine, no change.
 *   - New "Legal" vault department for court docs, restricted to
 *     darren/michele, with 3 starter folders.
 *
 * Run via: docker exec agentedge php /var/www/html/scripts/vault_dept_grants_2026_10_11.php
 */
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
$db = local_db();

// 1. Human Resources -> fully restricted to Darren + Michele
$db->prepare("UPDATE vault_depts SET restricted=1 WHERE slug='hr'")->execute();
echo "Human Resources (hr) set restricted=1.\n";
foreach (['darren@innovateonline.com', 'michele@innovateonline.com'] as $email) {
    $check = $db->prepare("SELECT 1 FROM vault_user_depts WHERE email=? AND dept_slug='hr'");
    $check->execute([$email]);
    if ($check->fetchColumn()) { echo "$email already granted hr.\n"; continue; }
    $db->prepare("INSERT INTO vault_user_depts (email, dept_slug) VALUES (?, 'hr')")->execute([$email]);
    echo "Granted $email access to Human Resources.\n";
}

// 2. New restricted "Legal" vault department for court docs etc.
$slug = 'legal';
$exists = $db->prepare("SELECT 1 FROM vault_depts WHERE slug=?");
$exists->execute([$slug]);
if ($exists->fetchColumn()) {
    echo "Dept '$slug' already exists, skipping create.\n";
} else {
    $db->prepare("INSERT INTO vault_depts (slug, name, sort_ord, restricted) VALUES (?,?,?,1)")
        ->execute([$slug, 'Legal', 35]);
    echo "Created restricted dept 'legal' (Legal), sort_ord=35.\n";
}
foreach (['darren@innovateonline.com', 'michele@innovateonline.com'] as $email) {
    $check = $db->prepare("SELECT 1 FROM vault_user_depts WHERE email=? AND dept_slug=?");
    $check->execute([$email, $slug]);
    if ($check->fetchColumn()) { echo "$email already granted $slug.\n"; continue; }
    $db->prepare("INSERT INTO vault_user_depts (email, dept_slug) VALUES (?,?)")->execute([$email, $slug]);
    echo "Granted $email access to Legal.\n";
}

// IMPORTANT: reusing the 'legal' slug (the old "Legal & Contracts" department
// used the same slug before the main reorg retired it) silently carries over
// any stale vault_user_depts rows still pointing at it — confirmed live:
// jeff@/carrie@/kris@ still had grants from the old department. Strip those
// out explicitly rather than assuming a deleted vault_depts row implies its
// grants were cleaned up too (they aren't — vault_user_depts has no FK
// cascade on vault_depts).
$stale = ['jeff@innovateonline.com', 'carrie@innovateonline.com', 'kris@innovateonline.com'];
foreach ($stale as $email) {
    $db->prepare("DELETE FROM vault_user_depts WHERE email=? AND dept_slug='legal'")->execute([$email]);
    echo "Removed stale grant (from the old, unrelated 'Legal & Contracts' dept): $email -> legal\n";
}

function mk_id(string $hint): string {
    return 'vf-legal-' . $hint . '-' . substr(md5($hint . microtime()), 0, 8);
}
$folders = ['Court Filings & Litigation', 'Demand Letters & Claims', 'Settlements & Agreements'];
foreach ($folders as $name) {
    $check = $db->prepare("SELECT 1 FROM vault_folders WHERE dept_slug=? AND name=?");
    $check->execute([$slug, $name]);
    if ($check->fetchColumn()) { echo "Folder '$name' already exists, skipping.\n"; continue; }
    $id = mk_id(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
    $db->prepare("INSERT INTO vault_folders (id, parent_id, name, visibility, dept_slug, created_by, created_at)
        VALUES (?, NULL, ?, 'dept', ?, ?, datetime('now'))")
        ->execute([$id, $name, $slug, 'darren@innovateonline.com']);
    echo "Created folder '$name' in Legal.\n";
}

echo "\n=== Verification ===\n";
print_r($db->query("SELECT * FROM vault_depts WHERE slug IN ('hr','legal')")->fetchAll(PDO::FETCH_ASSOC));
foreach ($db->query("SELECT * FROM vault_user_depts WHERE dept_slug IN ('hr','legal')")->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);
echo "Done.\n";
