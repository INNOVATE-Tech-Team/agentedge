<?php
// Permissions API — used by the intranet, CRM, and other apps to resolve
// a user's role and market center scope. No login required; token-gated.
//
// GET /api/permissions.php?email=<email>&token=<permissions_token>
//
// Response: { role, mc_slugs, is_staff, is_bic, is_mc_leader,
//             can_post_org_wide, can_post_bic, can_post_mc }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../roles.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$c     = cfg();
$token = $c['permissions_token'] ?? '';

// Token required — reject if not configured or mismatch.
$given = trim($_GET['token'] ?? $_SERVER['HTTP_X_PERMISSIONS_TOKEN'] ?? '');
if ($token === '' || $given === '') {
    http_response_code(401);
    echo json_encode(['error' => 'permissions_token not configured or missing']);
    exit;
}
if (!hash_equals($token, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

$email = strtolower(trim($_GET['email'] ?? ''));
if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'email required']);
    exit;
}

$perms = fetch_perms($email);
$role  = $perms['role'] ?? 'agent';
$mcs   = $perms['mc_slugs'] ?? [];

echo json_encode([
    'role'              => $role,
    'mc_slugs'          => $mcs,
    'is_staff'          => in_array($role, ['super_admin', 'staff'], true),
    'is_bic'            => $role === 'bic',
    'is_mc_leader'      => $role === 'mc_leader',
    'can_post_org_wide' => in_array($role, ['super_admin', 'staff'], true),
    'can_post_bic'      => in_array($role, ['super_admin', 'staff', 'bic'], true),
    'can_post_mc'       => in_array($role, ['super_admin', 'staff', 'bic', 'mc_leader'], true),
]);
