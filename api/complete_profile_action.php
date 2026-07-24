<?php
// Public "complete your profile" form backend — identified by a token
// (profile_completion_tokens), not a login session, so an agent can use the
// emailed link without needing to sign in first.
// GET  ?token=xxx        → {ok, name, missing:[{key,label}]} or {ok:false, error}
// POST {token, fields:{}} → saves only the fields that were actually missing
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
header('Content-Type: application/json');

function lookup_token_email(string $token): ?string {
    if ($token === '') return null;
    $st = local_db()->prepare("SELECT email FROM profile_completion_tokens WHERE token = ?");
    $st->execute([$token]);
    $email = $st->fetchColumn();
    return $email ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = lookup_token_email(trim($_GET['token'] ?? ''));
    if ($email === null) {
        echo json_encode(['ok' => false, 'error' => 'This link is invalid.']);
        exit;
    }

    $st = local_db()->prepare("SELECT full_name FROM agent_intake WHERE email = ?");
    $st->execute([$email]);
    $name    = $st->fetchColumn() ?: '';
    $missing = get_missing_required_fields($email);

    echo json_encode(['ok' => true, 'name' => $name, 'missing' => $missing]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = lookup_token_email(trim($body['token'] ?? ''));
    if ($email === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'This link is invalid.']);
        exit;
    }

    // Only ever fill in fields that were actually missing when the form
    // loaded — the client sends back exactly the keys it rendered, but
    // re-derive the missing set server-side too rather than trusting the
    // request, so this endpoint can't be used to overwrite fields the agent
    // already has on file.
    $missingKeys = array_column(get_missing_required_fields($email), 'key');
    $fields      = is_array($body['fields'] ?? null) ? $body['fields'] : [];

    $toSave = [];
    foreach ($missingKeys as $key) {
        $val = trim((string)($fields[$key] ?? ''));
        if ($val === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please fill in every field.']);
            exit;
        }
        $toSave[$key] = $val;
    }

    if ($toSave) {
        $db   = local_db();
        $now  = date('Y-m-d H:i:s');
        $cols = implode(',', array_keys($toSave));
        $phs  = implode(',', array_fill(0, count($toSave), '?'));
        $upds = implode(',', array_map(fn($k) => "$k=excluded.$k", array_keys($toSave)));

        $db->prepare(
            "INSERT INTO agent_intake (email,$cols,updated_at)
             VALUES (?,$phs,?)
             ON CONFLICT(email) DO UPDATE SET $upds, updated_at=excluded.updated_at"
        )->execute(array_merge([$email], array_values($toSave), [$now]));

        // If this save brought the profile to fully complete and it was
        // never marked submitted (e.g. an older agent who predates some of
        // these required fields), mark it now.
        if (empty(get_missing_required_fields($email))) {
            $db->prepare(
                "UPDATE agent_intake SET submitted=1, submitted_at=COALESCE(submitted_at, ?) WHERE email=? AND submitted=0"
            )->execute([$now, $email]);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
