<?php
// POST /api/swag_box_order.php
// Validates a swag-box order request from the logged-in agent, logs it,
// and emails lisa@innovateonline.com + kelsey@innovateonline.com to fulfill.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';

header('Content-Type: application/json');
$agent = require_login();
$myEmail = strtolower(trim($agent['email'] ?? ''));
$myName  = $agent['name'] ?? $myEmail;

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
if (empty($_SESSION['csrf']) || ($body['csrf'] ?? '') !== $_SESSION['csrf']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh the page.']);
    exit;
}

// Cooldown: one order per 90 days
$cooldown = local_db()->prepare(
    "SELECT ordered_at FROM swag_box_orders WHERE email=? AND ordered_at > datetime('now','-90 days') LIMIT 1"
);
$cooldown->execute([$myEmail]);
if ($cooldown->fetchColumn()) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'You already requested a swag box in the last 90 days.']);
    exit;
}

// Validate address fields
$line1 = trim($body['address_line1'] ?? '');
$line2 = trim($body['address_line2'] ?? '');
$city  = trim($body['city']  ?? '');
$state = strtoupper(trim($body['state'] ?? ''));
$zip   = trim($body['zip']   ?? '');

if ($line1 === '' || $city === '' || $state === '' || $zip === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Address, city, state, and ZIP are required.']);
    exit;
}

// Record the order
local_db()->prepare(
    "INSERT INTO swag_box_orders (email, agent_name, address_line1, address_line2, city, state, zip, ordered_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))"
)->execute([$myEmail, $myName, $line1, $line2, $city, $state, $zip]);

// Build the shipping address string
$addrParts = array_filter([$line1, $line2 ?: null]);
$addrFull  = implode(', ', $addrParts) . "\n" . $city . ', ' . $state . ' ' . $zip;

// Fulfillment recipients
define('SWAG_FULFILLMENT_EMAILS', ['lisa@innovateonline.com', 'kelseyabroussard@gmail.com']);

$subject = 'Recruiting Swag Box Request — ' . $myName;
$p = 'style="color:#444;font-size:15px;line-height:1.7;margin:0 0 14px"';
$emailHtml = notification_email_html(
    '<p ' . $p . '>A recruiting swag box was requested from AgentEdge.</p>'
    . '<table style="border-collapse:collapse;margin-bottom:20px;width:100%">'
    . '<tr><td style="padding:8px 12px;font-size:13px;font-weight:700;color:#888;white-space:nowrap;width:110px">Agent</td>'
    . '<td style="padding:8px 12px;font-size:14px;color:#1a1a1a">' . htmlspecialchars($myName, ENT_QUOTES) . '</td></tr>'
    . '<tr style="background:#f9fdf5"><td style="padding:8px 12px;font-size:13px;font-weight:700;color:#888;white-space:nowrap">Email</td>'
    . '<td style="padding:8px 12px;font-size:14px;color:#1a1a1a"><a href="mailto:' . htmlspecialchars($myEmail, ENT_QUOTES) . '" style="color:#5b8e0d">' . htmlspecialchars($myEmail, ENT_QUOTES) . '</a></td></tr>'
    . '<tr><td style="padding:8px 12px;font-size:13px;font-weight:700;color:#888;white-space:nowrap">Ship To</td>'
    . '<td style="padding:8px 12px;font-size:14px;color:#1a1a1a">' . nl2br(htmlspecialchars($addrFull, ENT_QUOTES)) . '</td></tr>'
    . '</table>'
    . '<p ' . $p . '>Please send a recruiting swag box to this agent at the address above.</p>'
    . '<p style="color:#888;font-size:12px;margin:0">Requested ' . date('F j, Y \a\t g:i A T') . '</p>'
);

$c = cfg();
$sent = false;
foreach (SWAG_FULFILLMENT_EMAILS as $recipient) {
    if (send_email_sendgrid($recipient, $subject, $emailHtml, $c, true)) {
        $sent = true;
    }
}

echo json_encode(['ok' => true, 'sent' => $sent]);
