<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $fv = fn($k) => trim($body[$k] ?? '');

    // ── Extract email and validate ────────────────────────────────────────────
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid email address is required.']);
        exit;
    }

    // ── Required fields check ─────────────────────────────────────────────────
    $required = [
        'full_name', 'phone', 'license_number', 'nar_number', 'mls_board',
        'office_location', 'birthday', 'mailing_address',
        'emergency_name', 'emergency_phone', 'bio',
    ];
    foreach ($required as $field) {
        if ($fv($field) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Required field missing: $field"]);
            exit;
        }
    }

    // ── Duplicate check ───────────────────────────────────────────────────────
    $dup = local_db()->prepare("SELECT id FROM agent_intake WHERE LOWER(email)=? AND submitted=1");
    $dup->execute([$email]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'This email has already submitted an intake form.']);
        exit;
    }

    // ── UPSERT into agent_intake ──────────────────────────────────────────────
    $fields = [
        'full_name', 'phone', 'license_number', 'license_state', 'license_exp',
        'nar_number', 'mls_board', 'mls_id', 'office_location', 'birthday',
        'mailing_address', 'spouse_name', 'emergency_name', 'emergency_phone', 'bio',
        'tshirt_size', 'is_military', 'first_responder', 'is_teacher',
        'phone_last4', 'referring_agent', 'languages',
    ];

    $cols = implode(',', $fields);
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    $upds = implode(',', array_map(fn($f) => "$f=excluded.$f", $fields));
    $now  = date('Y-m-d H:i:s');

    local_db()->prepare(
        "INSERT INTO agent_intake (email,$cols,submitted,submitted_at,updated_at)
         VALUES (?,$phs,1,?,?)
         ON CONFLICT(email) DO UPDATE SET
             $upds, submitted=1,
             submitted_at=COALESCE(agent_intake.submitted_at, excluded.submitted_at),
             updated_at=excluded.updated_at"
    )->execute(array_merge([$email], array_map($fv, $fields), [$now, $now]));

    // ── Add to onboard_queue if not already present ───────────────────────────
    $exists = local_db()->prepare("SELECT id FROM onboard_queue WHERE LOWER(agent_email)=?");
    $exists->execute([strtolower($email)]);
    if (!$exists->fetch()) {
        local_db()->prepare(
            "INSERT INTO onboard_queue (agent_email,agent_name,market_center,role,added_by,added_at,status,notes)
             VALUES (?,?,?,'agent','intake_form',?,?,?)"
        )->execute([
            $email,
            $fv('full_name'),
            $fv('office_location'),
            $now,
            'active',
            'Submitted via public onboarding intake form',
        ]);
    }

    echo json_encode(['ok' => true]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again later.']);
}
