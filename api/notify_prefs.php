<?php
// GET  — returns the signed-in agent's notification preferences.
// POST — saves them.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
$email = strtolower($agent['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = local_db()->prepare("SELECT notify_email, notify_sms, sms_phone FROM notification_prefs WHERE email=?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'notify_email' => (int)($row['notify_email'] ?? 1),
        'notify_sms'   => (int)($row['notify_sms']   ?? 0),
        'sms_phone'    => $row['sms_phone'] ?? '',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'GET or POST only']); exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

$notifyEmail = empty($in['notify_email']) ? 0 : 1;
$notifySms   = empty($in['notify_sms'])   ? 0 : 1;
$smsPhone    = trim($in['sms_phone'] ?? '');

// Basic phone validation — digits, spaces, dashes, parens, plus sign only.
if ($smsPhone !== '' && !preg_match('/^[\d\s\-\(\)\+]{7,20}$/', $smsPhone)) {
    http_response_code(400); echo json_encode(['error'=>'invalid phone number']); exit;
}

// Require a phone number if SMS is being enabled.
if ($notifySms && $smsPhone === '') {
    http_response_code(400); echo json_encode(['error'=>'phone number required to enable SMS']); exit;
}

// Compare against the prior row so the opt-in confirmation text only fires
// on an actual new opt-in (SMS newly turned on, or re-pointed at a different
// number) — not on every unrelated profile-prefs save. This confirmation is
// the carrier-required "opt-in message": it's the literal text a TCR
// reviewer can be told to expect the moment this toggle is flipped on.
$prior = local_db()->prepare("SELECT notify_sms, sms_phone FROM notification_prefs WHERE email=?");
$prior->execute([$email]);
$priorRow = $prior->fetch(PDO::FETCH_ASSOC);
$isNewOptIn = $notifySms && $smsPhone !== '' && (
    empty($priorRow['notify_sms']) || ($priorRow['sms_phone'] ?? '') !== $smsPhone
);

local_db()->prepare(
    "INSERT INTO notification_prefs (email, notify_email, notify_sms, sms_phone, updated_at)
     VALUES (?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
         notify_email = excluded.notify_email,
         notify_sms   = excluded.notify_sms,
         sms_phone    = excluded.sms_phone,
         updated_at   = excluded.updated_at"
)->execute([$email, $notifyEmail, $notifySms, $smsPhone]);

if ($isNewOptIn) {
    // Sent synchronously (not queued) so it goes out immediately, matching
    // what the campaign's Opt-in Message evidence describes: the confirmation
    // arrives the moment the toggle is flipped, before any other text.
    send_sms_twilio(
        $smsPhone,
        "INNOVATE Real Estate: You're opted in for account & company text notifications. "
        . "Msg frequency varies (a few/month). Msg&Data rates may apply. Reply STOP to cancel, HELP for help.",
        cfg()
    );
}

echo json_encode(['ok' => true]);
