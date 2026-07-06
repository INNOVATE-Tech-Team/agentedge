<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/crypto.php';
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
        'office_location', 'birthday', 'address_line1', 'city', 'state', 'zip',
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
        'personal_email', 'commissions_email',
        'address_line1', 'address_line2', 'city', 'state', 'zip', 'country',
        'drivers_license', 'gender',
        'website', 'additional_websites', 'facebook', 'linkedin', 'skype', 'email_signature',
        'corporation_start', 'corporation_end', 'career_start',
        'prior_occupation', 'prior_affiliation', 'specialty',
        'full_time', 'show_on_internet',
        'personal_tax_id_enc', 'corporate_tax_id_enc',
    ];

    // full_time/show_on_internet are checkboxes (default checked); the two
    // tax ID columns hold an encrypted value derived from a differently-named
    // plaintext body field, never the plaintext itself.
    $resolveField = function (string $f) use ($fv, $body): string {
        switch ($f) {
            case 'full_time':
            case 'show_on_internet':
                return isset($body[$f]) ? ($body[$f] ? '1' : '0') : '1';
            case 'personal_tax_id_enc':
                return tax_id_encrypt($fv('personal_tax_id'));
            case 'corporate_tax_id_enc':
                return tax_id_encrypt($fv('corporate_tax_id'));
            default:
                return $fv($f);
        }
    };

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
    )->execute(array_merge([$email], array_map($resolveField, $fields), [$now, $now]));

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

    // ── Email notification ────────────────────────────────────────────────────
    $submitterName  = $fv('full_name');
    $submitterEmail = $email;
    $submitterPhone = $fv('phone');
    $officeLocation = $fv('office_location');

    $subject = "New Intake Form Submission — {$submitterName}";
    $body    = "A new agent has submitted the onboarding intake form.\n\n"
             . "Name:   {$submitterName}\n"
             . "Email:  {$submitterEmail}\n"
             . "Phone:  {$submitterPhone}\n"
             . "Office: {$officeLocation}\n\n"
             . "View their profile in AgentEdge:\n"
             . "https://agentedge.innovateonline.com/backoffice_agents.php";

    $c = cfg();
    send_email_sendgrid('onboarding@innovateonline.com', $subject, $body, $c);
    send_email_sendgrid('darren@innovateonline.com',     $subject, $body, $c);

    echo json_encode(['ok' => true]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again later.']);
}
