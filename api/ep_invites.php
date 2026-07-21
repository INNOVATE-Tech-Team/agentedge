<?php
// Event Planner — email invitations. Organizer-only. Reuses the existing
// SendGrid/notification_queue infrastructure from lib/notifications.php
// (the same one Announcements uses) rather than sending mail directly.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require __DIR__ . '/../lib/notifications.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email']));

const EP_INVITE_MAX_PER_SEND = 200;

function ep_inv_load_event(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
    $s->execute([$id]);
    $ev = $s->fetch(PDO::FETCH_ASSOC);
    return $ev ?: null;
}

function ep_inv_can_manage(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error' => 'Missing event_id']); exit; }
    $ev = ep_inv_load_event($db, $eventId);
    if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
    if (!ep_inv_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['error' => 'Not authorized']); exit; }

    $rows = $db->prepare("SELECT * FROM ep_invites WHERE event_id=? ORDER BY invited_at DESC");
    $rows->execute([$eventId]);
    echo json_encode(['invites' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = (int)($body['event_id'] ?? 0);
if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id']); exit; }
$ev = ep_inv_load_event($db, $eventId);
if (!$ev) { echo json_encode(['ok' => false, 'error' => 'Event not found']); exit; }
if (!ep_inv_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }
if ($ev['status'] !== 'published') {
    echo json_encode(['ok' => false, 'error' => 'Publish the event before sending invites.']); exit;
}

// Parse a free-form blob: newlines, commas, or whitespace-separated addresses.
$raw    = (string)($body['emails'] ?? '');
$pieces = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
$valid   = [];
$invalid = 0;
foreach ($pieces as $p) {
    $addr = strtolower(trim($p));
    if ($addr === '') continue;
    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) $valid[$addr] = true;
    else $invalid++;
}
$valid = array_keys($valid);

if (!$valid) { echo json_encode(['ok' => false, 'error' => 'No valid email addresses found.']); exit; }
if (count($valid) > EP_INVITE_MAX_PER_SEND) {
    echo json_encode(['ok' => false, 'error' => 'Too many addresses at once (max ' . EP_INVITE_MAX_PER_SEND . ' per send).']); exit;
}

// Skip anyone already invited to this event.
$already = $db->prepare("SELECT email FROM ep_invites WHERE event_id=?");
$already->execute([$eventId]);
$skip = array_flip(array_map('strtolower', $already->fetchAll(PDO::FETCH_COLUMN)));
$toSend = array_values(array_filter($valid, fn($e) => !isset($skip[$e])));

if (!$toSend) { echo json_encode(['ok' => true, 'queued' => 0, 'skipped' => count($valid), 'invalid' => $invalid]); exit; }

$publicUrl = 'https://agentedge.innovateonline.com/event_public.php?t=' . urlencode($ev['public_token']);
$when      = $ev['start_date'] . ($ev['start_time'] ? ' at ' . $ev['start_time'] : '');
$subject   = "You're invited: " . $ev['title'];
$body_     = $ev['title'] . "\n" . $when . ($ev['location'] ? "\n" . $ev['location'] : '') . "\n\n"
           . ($ev['description'] ? $ev['description'] . "\n\n" : '')
           . "RSVP here: " . $publicUrl;

$insInvite = $db->prepare("INSERT INTO ep_invites (event_id, email, invited_by) VALUES (?,?,?)");
$insQueue  = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name) VALUES (?, 'email', ?, ?, '', ?, ?)");
foreach ($toSend as $addr) {
    $insInvite->execute([$eventId, $addr, $email]);
    $insQueue->execute([$addr, $subject, $body_, $email, $agent['name'] ?? '']);
}

echo json_encode(['ok' => true, 'queued' => count($toSend), 'skipped' => count($valid) - count($toSend), 'invalid' => $invalid]);
dispatch_notification_queue();
