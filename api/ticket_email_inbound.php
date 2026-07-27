<?php
// Inbound email → ticket reply. SendGrid Inbound Parse posts here whenever
// mail arrives at reply+{id}-{token}@{ticket_reply_domain} (see config.sample.php
// for the one-time MX + Inbound Parse setup). No login involved — the sender
// is authorized by matching their From address against the ticket's agent,
// its CCs, or the staff/admin roster.
//
// Security model: the address itself carries an unguessable per-ticket HMAC
// token (see ticket_reply_token() in lib/notifications.php), so knowing a
// ticket id alone isn't enough to post into it. The sender-email check below
// is defense in depth on top of that, not the primary gate.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST required'], 405);

// Auto-replies (out-of-office, mailer-daemon bounces) must never become ticket
// messages — otherwise a staff/agent OOO reply to a notification email could
// bounce back and forth, re-triggering a notification each time.
$rawHeaders = $_POST['headers'] ?? '';
if (preg_match('/^(Auto-Submitted):\s*(?!no\b)\S/mi', $rawHeaders)
    || preg_match('/^(X-Autoreply|X-Autorespond):/mi', $rawHeaders)) {
    json_out(['ok'=>true, 'skipped'=>'auto-submitted']);
}

// envelope is the authoritative recipient list (JSON: {"to":["a@b.com"],"from":"..."}),
// unlike the free-text "to" header which can carry a display name or multiple addresses.
$envelope = json_decode($_POST['envelope'] ?? '', true) ?: [];
$toList   = $envelope['to'] ?? [];
if (!$toList && !empty($_POST['to'])) $toList = [$_POST['to']];

$domain = preg_quote((cfg()['ticket_reply_domain'] ?? '') ?: 'reply.innovateonline.com', '/');
$ticketId = 0;
$token    = '';
foreach ($toList as $addr) {
    if (preg_match('/reply\+(\d+)-([a-f0-9]{12})@' . $domain . '/i', (string)$addr, $m)) {
        $ticketId = (int)$m[1];
        $token    = $m[2];
        break;
    }
}
if (!$ticketId || !hash_equals(ticket_reply_token($ticketId), $token)) {
    json_out(['ok'=>false, 'error'=>'no matching ticket address'], 202);
}

$db = local_db();
$s  = $db->prepare("SELECT * FROM support_tickets WHERE id=?");
$s->execute([$ticketId]);
$tkt = $s->fetch(PDO::FETCH_ASSOC);
if (!$tkt) json_out(['ok'=>false, 'error'=>'ticket not found'], 202);

// Extract a bare email address from a "Name <email@x.com>" or plain From header.
$fromRaw = $_POST['from'] ?? '';
$senderEmail = '';
if (preg_match('/<([^>]+)>/', $fromRaw, $m)) {
    $senderEmail = strtolower(trim($m[1]));
} else {
    $senderEmail = strtolower(trim($fromRaw));
}
if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) json_out(['ok'=>false, 'error'=>'invalid sender'], 202);

$isStaff   = email_is_staff($senderEmail);
$isAgent   = strtolower($tkt['agent_email']) === $senderEmail;
$isCc      = in_array($senderEmail, array_map('strtolower', support_ticket_cc_emails($ticketId)), true);
if (!$isStaff && !$isAgent && !$isCc) {
    json_out(['ok'=>false, 'error'=>'sender not authorized on this ticket'], 202);
}

$bodyText = strip_email_quote((string)($_POST['text'] ?? ''));
if ($bodyText === '' && !empty($_POST['html'])) {
    $bodyText = strip_email_quote(trim(html_entity_decode(strip_tags((string)$_POST['html']), ENT_QUOTES)));
}
if ($bodyText === '') json_out(['ok'=>true, 'skipped'=>'empty body after quote-stripping']);

record_ticket_reply($db, $tkt, $senderEmail, $isStaff, $bodyText);
dispatch_notification_queue();
