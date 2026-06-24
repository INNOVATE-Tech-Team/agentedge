<?php
// ONE-TIME migration: imports vault_depts, vault_folders, and vault_files
// from the everythinginnovate.com intranet PostgreSQL into AgentEdge's SQLite.
//
// Run from the server:  php vault_migrate.php
// Or via browser (admin only, then DELETE this file immediately after).
//
// Safe to run multiple times — uses INSERT OR IGNORE on primary keys.

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

// ── PostgreSQL connection ──────────────────────────────────────────────────────
// These match the intranet's docker-compose service name "db" on port 5433 (host).
// On Lightsail, the intranet DB is reachable on localhost:5433 (host-port mapping).
$pgHost = getenv('PG_HOST') ?: 'localhost';
$pgPort = getenv('PG_PORT') ?: '5433';
$pgDb   = getenv('PG_DB')   ?: 'intranet';
$pgUser = getenv('PG_USER') ?: 'postgres';
$pgPass = getenv('PG_PASS') ?: 'sR57DmXKgrdkEWx9u2qYfTQNLAZGbhz0';

try {
    $pg = new PDO("pgsql:host=$pgHost;port=$pgPort;dbname=$pgDb", $pgUser, $pgPass,
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\Exception $e) {
    log_line("ERROR: cannot connect to PostgreSQL: " . $e->getMessage());
    exit(1);
}

$db = local_db();

// ── 1. Departments ─────────────────────────────────────────────────────────────
log_line("→ Migrating departments…");
$insDept = $db->prepare("INSERT OR IGNORE INTO vault_depts (slug, name, sort_ord) VALUES (?, ?, ?)");
$deptRows = $pg->query("SELECT slug, name FROM department ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$deptOrder = 0;
foreach ($deptRows as $d) {
    $insDept->execute([$d['slug'], $d['name'], ($deptOrder += 10)]);
    log_line("  dept: {$d['slug']} → {$d['name']}");
}
log_line("  Done: " . count($deptRows) . " departments.");

// ── 2. Folders ─────────────────────────────────────────────────────────────────
log_line("→ Migrating folders…");
$pgFolders = $pg->query(
    'SELECT id, name, "parentId", visibility, "departmentSlug" FROM doc_folder ORDER BY "createdAt"'
)->fetchAll(PDO::FETCH_ASSOC);

// Map intranet visibility → vault visibility
$visMap = ['public' => 'public', 'department' => 'dept', 'super_admin' => 'admin'];

$insFolder = $db->prepare(
    "INSERT OR IGNORE INTO vault_folders (id, parent_id, name, visibility, dept_slug, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, 'migration', datetime('now'))"
);
$folderCount = 0;
foreach ($pgFolders as $f) {
    $vis      = $visMap[$f['visibility']] ?? 'public';
    $deptSlug = $f['departmentSlug'] ?? '';
    $insFolder->execute([$f['id'], $f['parentId'] ?: null, $f['name'], $vis, $deptSlug]);
    $folderCount++;
}
log_line("  Done: $folderCount folders.");

// ── 3. Files ───────────────────────────────────────────────────────────────────
log_line("→ Migrating files…");
$pgFiles = $pg->query(
    'SELECT id, "folderId", name, "mimeType", "sizeBytes", "storageKey", "ownerEmail", "createdAt"
     FROM doc_file ORDER BY "createdAt"'
)->fetchAll(PDO::FETCH_ASSOC);

$insFile = $db->prepare(
    "INSERT OR IGNORE INTO vault_files (id, folder_id, name, mime_type, size_bytes, storage_key, uploaded_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$fileCount = 0;
foreach ($pgFiles as $f) {
    $insFile->execute([
        $f['id'],
        $f['folderId'] ?: null,
        $f['name'],
        $f['mimeType'] ?? '',
        (int)($f['sizeBytes'] ?? 0),
        $f['storageKey'],
        $f['ownerEmail'] ?? 'migration',
        $f['createdAt'] ?? date('Y-m-d H:i:s'),
    ]);
    $fileCount++;
}
log_line("  Done: $fileCount files.");

// ── 4. User department assignments ─────────────────────────────────────────────
log_line("→ Migrating user department assignments…");
$pgUsers = $pg->query(
    'SELECT email, "departmentSlug" FROM "user" WHERE "departmentSlug" IS NOT NULL'
)->fetchAll(PDO::FETCH_ASSOC);

$insUd = $db->prepare("INSERT OR IGNORE INTO vault_user_depts (email, dept_slug) VALUES (?, ?)");
$udCount = 0;
foreach ($pgUsers as $u) {
    if ($u['departmentSlug']) {
        $insUd->execute([strtolower($u['email']), $u['departmentSlug']]);
        $udCount++;
    }
}
log_line("  Done: $udCount user-dept assignments.");

log_line("");
log_line("✓ Migration complete. DELETE vault_migrate.php from the server now.");
