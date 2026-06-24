<?php
// ONE-TIME migration: reads pipe-separated data files exported from the intranet
// PostgreSQL and imports them into AgentEdge's SQLite vault tables.
//
// Data files must be in the same directory as this script (already on server):
//   vault_depts.psv, vault_folders.psv, vault_files.psv, vault_users.psv
//
// Run: docker exec agentedge php /var/www/html/vault_migrate.php
// Or browser (admin only). DELETE this file and the .psv files when done.

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/roles.php';
    $agent = require_login();
    $perms = current_perms();
    if (empty($perms['isSuperAdmin'])) { http_response_code(403); exit('Forbidden'); }
}

require_once __DIR__ . '/local_db.php';

function log_line(string $msg): void {
    if (PHP_SAPI === 'cli') { echo $msg . "\n"; } else { echo nl2br(htmlspecialchars($msg)) . "<br>\n"; flush(); }
}

function read_psv(string $path): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rows[] = explode('|', $line);
    }
    return $rows;
}

$base = __DIR__;
$db   = local_db();
$visMap = ['public' => 'public', 'department' => 'dept', 'super_admin' => 'admin'];

// ── 1. Departments ─────────────────────────────────────────────────────────────
log_line("→ Migrating departments…");
$insDept = $db->prepare("INSERT OR IGNORE INTO vault_depts (slug, name, sort_ord) VALUES (?, ?, ?)");
$ord = 0;
foreach (read_psv("$base/vault_depts.psv") as $r) {
    [$slug, $name] = $r;
    $insDept->execute([$slug, $name, ($ord += 10)]);
}
$count = $db->query("SELECT COUNT(*) FROM vault_depts")->fetchColumn();
log_line("  Done: $count departments.");

// ── 2. Folders ─────────────────────────────────────────────────────────────────
log_line("→ Migrating folders…");
$insFolder = $db->prepare(
    "INSERT OR IGNORE INTO vault_folders (id, parent_id, name, visibility, dept_slug, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
);
$n = 0;
foreach (read_psv("$base/vault_folders.psv") as $r) {
    // id | name | parentId | visibility | departmentSlug | ownerEmail
    [$id, $name, $parentId, $vis, $deptSlug, $owner] = array_pad($r, 6, '');
    $vis = $visMap[$vis] ?? 'public';
    $insFolder->execute([$id, $parentId ?: null, $name, $vis, $deptSlug, $owner ?: 'migration']);
    $n++;
}
log_line("  Done: $n folders.");

// ── 3. Files ───────────────────────────────────────────────────────────────────
log_line("→ Migrating files…");
$insFile = $db->prepare(
    "INSERT OR IGNORE INTO vault_files (id, folder_id, name, mime_type, size_bytes, storage_key, uploaded_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))"
);
$n = 0;
foreach (read_psv("$base/vault_files.psv") as $r) {
    // id | folderId | name | mimeType | sizeBytes | storageKey | ownerEmail
    [$id, $folderId, $name, $mime, $size, $key, $owner] = array_pad($r, 7, '');
    $insFile->execute([$id, $folderId ?: null, $name, $mime, (int)$size, $key, $owner ?: 'migration']);
    $n++;
}
log_line("  Done: $n files.");

// ── 4. User dept assignments ────────────────────────────────────────────────────
log_line("→ Migrating user-department assignments…");
$insUd = $db->prepare("INSERT OR IGNORE INTO vault_user_depts (email, dept_slug) VALUES (?, ?)");
$n = 0;
foreach (read_psv("$base/vault_users.psv") as $r) {
    [$email, $deptSlug] = array_pad($r, 2, '');
    if ($email && $deptSlug) { $insUd->execute([strtolower($email), $deptSlug]); $n++; }
}
log_line("  Done: $n user-dept assignments.");

log_line("");
log_line("✓ Migration complete.");
log_line("  Delete vault_migrate.php and the four .psv files from the server.");
