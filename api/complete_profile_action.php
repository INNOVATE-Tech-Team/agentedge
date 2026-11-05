<?php
// Public "complete your profile" form backend — identified by a token
// (profile_completion_tokens), not a login session, so an agent can use the
// emailed link without needing to sign in first.
// GET  ?token=xxx        → {ok, name, missing:[{key,label}], optional:[...], status} or {ok:false, error}
// POST {token, fields:{}} → saves only the fields that were actually missing
//
// Completion links are for EXISTING agents. Their Complete/Incomplete status
// is computed live (lib/agent_profile.php), so filling the last missing field
// makes the profile Complete without touching agent_intake.submitted — that
// flag stays an onboarding-only concept, and nothing here sends any email.
// Agents actively onboarding are pointed back to their intake link instead.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
header('Content-Type: application/json');

const PROFILE_LINK_ERRORS = [
    'invalid' => 'This link is invalid.',
    'expired' => 'This link has expired. Please ask the INNOVATE team for a new one.',
    'revoked' => 'This link has been replaced by a newer one. Please use the most recent email we sent you.',
];
const PROFILE_LINK_ONBOARDING_ERROR = 'You are currently going through onboarding. Please use the intake form link from your onboarding email instead.';

// Resolves the token or ends the request with the right message.
function require_usable_link(string $token): string {
    $link = profile_link_lookup(local_db(), $token);
    if ($link['state'] !== 'ok') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') http_response_code(400);
        echo json_encode(['ok' => false, 'error' => PROFILE_LINK_ERRORS[$link['state']], 'link_state' => $link['state']]);
        exit;
    }
    $email = strtolower(trim($link['email']));
    if (is_actively_onboarding($email)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') http_response_code(409);
        echo json_encode(['ok' => false, 'error' => PROFILE_LINK_ONBOARDING_ERROR, 'link_state' => 'onboarding']);
        exit;
    }
    return $email;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = require_usable_link(trim($_GET['token'] ?? ''));

    $st = local_db()->prepare("SELECT full_name FROM agent_intake WHERE email = ?");
    $st->execute([$email]);
    $name     = $st->fetchColumn() ?: '';
    $missing  = get_missing_required_fields($email);
    $optional = get_blank_optional_fields($email);

    echo json_encode(['ok' => true, 'name' => $name, 'missing' => $missing, 'optional' => $optional,
                      'status' => profile_status($email)['status']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = require_usable_link(trim($body['token'] ?? ''));

    // Only ever fill in fields that were actually missing when the form
    // loaded — the client sends back exactly the keys it rendered, but
    // re-derive the missing set server-side too rather than trusting the
    // request, so this endpoint can't be used to overwrite fields the agent
    // already has on file.
    $missingKeys  = array_column(get_missing_required_fields($email), 'key');
    $optionalKeys = array_column(get_blank_optional_fields($email), 'key');
    $fields       = is_array($body['fields'] ?? null) ? $body['fields'] : [];

    $toSave = [];
    foreach ($missingKeys as $key) {
        $val = trim((string)($fields[$key] ?? ''));
        if ($val === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please fill in every field.']);
            exit;
        }
        if ($key === 'birthday' && birthday_parts($val) === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid birthday.']);
            exit;
        }
        $toSave[$key] = $val;
    }
    // Optional/recommended fields save if provided, but never block the
    // submission — an agent who skips one just doesn't get it recorded.
    foreach ($optionalKeys as $key) {
        $val = trim((string)($fields[$key] ?? ''));
        if ($val !== '') $toSave[$key] = $val;
    }

    if ($toSave) {
        $db   = local_db();
        $now  = date('Y-m-d H:i:s');
        $cols = implode(',', array_keys($toSave));
        $phs  = implode(',', array_fill(0, count($toSave), '?'));
        // A row that already has a value is never overwritten, even if the
        // missing set changed between the GET and this POST.
        $upds = implode(',', array_map(fn($k) => "$k=CASE WHEN TRIM(COALESCE(agent_intake.$k,''))='' THEN excluded.$k ELSE agent_intake.$k END", array_keys($toSave)));

        $db->prepare(
            "INSERT INTO agent_intake (email,$cols,updated_at)
             VALUES (?,$phs,?)
             ON CONFLICT(email) DO UPDATE SET $upds, updated_at=excluded.updated_at"
        )->execute(array_merge([$email], array_values($toSave), [$now]));
    }

    echo json_encode(['ok' => true, 'status' => profile_status($email)['status']]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
