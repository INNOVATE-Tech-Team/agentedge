<?php
// Logged-in agent's own profile-completeness check — backs the "finish your
// profile" popup on index.php. GET only, no params: always checks the
// signed-in agent, never someone else's.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$missing = get_missing_required_fields($agent['email'] ?? '');
echo json_encode(['complete' => empty($missing), 'missing' => $missing]);
