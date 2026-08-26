<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/crypto.php';
require_once __DIR__ . '/../lib/onboarding.php';
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

    // ── Tokenized link (from an automated/resent intake email) pins the
    // submission to the onboarding-queue row it was generated for — the
    // client-supplied email above is only trusted when no token is present
    // (the legacy, self-declared-email flow).
    $qid = (int)($body['qid'] ?? 0);
    if ($qid > 0) {
        $token = (string)($body['token'] ?? '');
        if ($token === '' || !hash_equals(intake_link_token($qid), $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'This link is invalid or has expired.']);
            exit;
        }
        $qst = local_db()->prepare("SELECT agent_email FROM onboard_queue WHERE id = ?");
        $qst->execute([$qid]);
        $queueEmail = $qst->fetchColumn();
        if (!$queueEmail) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'This link is invalid or has expired.']);
            exit;
        }
        $email = strtolower(trim($queueEmail));
    }

    // An agent can hold multiple MLS memberships (see agent_mls_memberships
    // below), but agent_intake.mls_board/mls_id remain single-value columns
    // other code still reads directly. Mirror the first membership into them
    // here, before the required-field check and the UPSERT below, both of
    // which read $body['mls_board']/['mls_id'] directly.
    if (is_array($body['mls_memberships'] ?? null)) {
        $firstMembership = $body['mls_memberships'][0] ?? [];
        $body['mls_board'] = trim($firstMembership['mls_association'] ?? '');
        $body['mls_id']    = trim($firstMembership['mls_number'] ?? '');
    }

    // ── Required fields check ─────────────────────────────────────────────────
    $required = [
        'full_name', 'phone', 'license_number', 'nar_number', 'mls_board',
        'office_location', 'birthday', 'address_line1', 'city', 'state', 'zip',
        'emergency_name', 'emergency_phone', 'bio', 'referring_agent',
    ];
    foreach ($required as $field) {
        if ($fv($field) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Required field missing: $field"]);
            exit;
        }
    }

    // ── Duplicate check ───────────────────────────────────────────────────────
    $dup = local_db()->prepare("SELECT email FROM agent_intake WHERE LOWER(email)=? AND submitted=1");
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
        'website', 'additional_websites', 'facebook', 'linkedin', 'skype', 'instagram',
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

    // ── Additional licenses (rewritten in full on every submit) ──────────────
    local_db()->prepare("DELETE FROM agent_intake_licenses WHERE agent_email=?")->execute([$email]);
    $additionalLicenses = is_array($body['additional_licenses'] ?? null) ? $body['additional_licenses'] : [];
    $insLicense = local_db()->prepare(
        "INSERT INTO agent_intake_licenses (agent_email, license_number, license_state, license_exp) VALUES (?,?,?,?)"
    );
    foreach ($additionalLicenses as $lic) {
        $num   = trim($lic['license_number'] ?? '');
        $state = trim($lic['license_state'] ?? '');
        $exp   = trim($lic['license_exp'] ?? '');
        if ($num === '' && $state === '' && $exp === '') continue;
        $insLicense->execute([$email, $num, $state, $exp]);
    }

    // ── MLS memberships (rewritten in full on every submit) ──────────────────
    local_db()->prepare("DELETE FROM agent_mls_memberships WHERE agent_email=?")->execute([$email]);
    $mlsMemberships = is_array($body['mls_memberships'] ?? null) ? $body['mls_memberships'] : [];
    $insMembership = local_db()->prepare(
        "INSERT INTO agent_mls_memberships (agent_email, mls_association, mls_number) VALUES (?,?,?)"
    );
    foreach ($mlsMemberships as $mem) {
        $assoc  = trim($mem['mls_association'] ?? '');
        $number = trim($mem['mls_number'] ?? '');
        if ($assoc === '' && $number === '') continue;
        $insMembership->execute([$email, $assoc, $number]);
    }

    // ── Add to onboard_queue (also seeds onboard_steps + notifications) ──────
    // Must go through the shared helper, not a raw INSERT — it's the only
    // place that seeds onboard_steps from onboard_tools(), so the checklist
    // isn't left empty for agents who come in via this public form.
    $queueResult = queue_onboarding_agent(
        local_db(),
        $email,
        $fv('full_name'),
        [['market_center' => $fv('office_location'), 'state_code' => '']],
        null,
        'intake_form',
        '',
        '',
        'agent',
        'Submitted via public onboarding intake form',
        '',
        $fv('phone')
    );

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
    notify_upline_intake_submitted($submitterName, $submitterEmail, $officeLocation);
    notify_intake_summary_admins($submitterEmail);

    // Flag it in the onboarding queue itself too — whoever added this agent
    // (or the standing onboarding CC list, for CRM-pushed/self-submitted
    // entries with no specific added_by) needs to know their intake data is
    // now ready to view, not just that ops got a summary email.
    $queueRow = local_db()->prepare("SELECT added_by FROM onboard_queue WHERE id = ?");
    $queueRow->execute([$queueResult['id']]);
    notify_intake_completed($submitterName, $submitterEmail, $queueResult['id'], (string)($queueRow->fetchColumn() ?: ''));

    echo json_encode(['ok' => true]);
    // Drain notification_queue in-request so staff alerts go out immediately
    // instead of waiting on the next cron cycle — wrapped so a delivery
    // hiccup here can never turn an already-succeeded submission into an
    // error response (the JSON success above is already sent).
    try { dispatch_notification_queue(); } catch (\Throwable $e) {}

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again later.']);
}
