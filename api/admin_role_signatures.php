<?php
// Admin API for per-role email signature management.
// Actions: get (load one role's sig), save (write one role's sig).
// Restricted to super_admin only.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

$agent = current_agent();
if (!$agent || empty(current_perms()['isSuperAdmin'])) {
    json_out(['ok' => false, 'error' => 'Unauthorized'], 403);
}

$raw    = file_get_contents('php://input');
$body   = $raw ? (json_decode($raw, true) ?? []) : [];
$action = trim($body['action'] ?? $_GET['action'] ?? '');

$validRoles = ['default', 'admin', 'staff', 'recruiter', 'mc_leader'];

if ($action === 'get') {
    $role = trim($body['role'] ?? $_GET['role'] ?? 'default');
    if (!in_array($role, $validRoles, true)) json_out(['ok' => false, 'error' => 'Invalid role']);
    $db  = local_db();
    $st  = $db->prepare("SELECT * FROM role_signatures WHERE role=?");
    $st->execute([$role]);
    $sig = $st->fetch(PDO::FETCH_ASSOC) ?: ['role' => $role, 'display_name' => '', 'title' => '', 'phone' => '', 'website_url' => '', 'use_custom' => 0, 'custom_html' => ''];
    json_out(['ok' => true, 'sig' => $sig]);
}

if ($action === 'save') {
    $role = trim($body['role'] ?? '');
    if (!in_array($role, $validRoles, true)) json_out(['ok' => false, 'error' => 'Invalid role']);

    $db = local_db();
    $db->prepare(
        "INSERT INTO role_signatures (role, display_name, title, phone, website_url, use_custom, custom_html, updated_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)
         ON CONFLICT(role) DO UPDATE SET
             display_name = excluded.display_name,
             title        = excluded.title,
             phone        = excluded.phone,
             website_url  = excluded.website_url,
             use_custom   = excluded.use_custom,
             custom_html  = excluded.custom_html,
             updated_at   = excluded.updated_at,
             updated_by   = excluded.updated_by"
    )->execute([
        $role,
        trim($body['display_name'] ?? ''),
        trim($body['title']        ?? ''),
        trim($body['phone']        ?? ''),
        trim($body['website_url']  ?? ''),
        empty($body['use_custom'])  ? 0 : 1,
        trim($body['custom_html']  ?? ''),
        $agent['email'],
    ]);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
