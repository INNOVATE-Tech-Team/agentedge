<?php
// SendGrid Event Webhook receiver — feeds the Opens/Clicks/Bounces numbers on
// the Company Email report (backoffice_email.php). Only events for emails
// sent through Company Email carry a company_email_id custom_arg (set in
// send_email_sendgrid()); events for other mail (tickets, onboarding, etc.)
// have no custom_arg and are ignored here.
//
// Registered 2026-09-03 (Settings > Mail Settings > Event Webhook, "AgentEdge
// Company Email Events", webhook id 463eee84-7dfd-4f83-bf27-3a7a28bf8a7c):
// Delivered/Opened/Clicked/Bounced/Dropped, Signature Verification on, key in
// config.php's sendgrid_webhook_verification_key. Until that key is set, this
// endpoint accepts requests without verifying them at all.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'POST required'], 405);
}

$raw = file_get_contents('php://input');

$verifyKey = trim(cfg()['sendgrid_webhook_verification_key'] ?? '');
if ($verifyKey !== '') {
    $signature = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '';
    if (!sendgrid_verify_webhook_signature($raw, $signature, $timestamp, $verifyKey)) {
        json_out(['ok'=>false,'error'=>'Invalid signature'], 403);
    }
}

// SendGrid's Event Webhook signing is ECDSA (P-256 curve) over SHA-256, not
// Ed25519 — confirmed 2026-09-03 against the real key SendGrid issued
// (starts with the DER prefix for id-ecPublicKey/prime256v1, not a raw
// 32-byte Ed25519 key). An earlier draft of this function used libsodium's
// sodium_crypto_sign_verify_detached(), which is Ed25519-only and would have
// rejected every genuinely-signed request the moment the key was set —
// caught before that happened, not after. SendGrid's own EventWebhook PHP
// library verifies the same way: openssl_verify() with OPENSSL_ALGO_SHA256
// over (timestamp . payload), using the dashboard-provided key as a PEM
// public key (the base64 string is the DER SubjectPublicKeyInfo — PEM is
// just that DER content wrapped with header/footer lines).
function sendgrid_verify_webhook_signature(string $rawBody, string $signatureB64, string $timestamp, string $publicKeyB64): bool {
    if ($signatureB64 === '' || $timestamp === '') return false;
    $signature = base64_decode($signatureB64, true);
    if ($signature === false || $publicKeyB64 === '') return false;

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKeyB64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) return false;

    $result = openssl_verify($timestamp . $rawBody, $signature, $pubKey, OPENSSL_ALGO_SHA256);
    return $result === 1;
}

$events = json_decode($raw, true);
if (!is_array($events)) json_out(['ok'=>true]); // nothing parseable — ack anyway so SendGrid doesn't retry forever

$db  = local_db();
$ins = $db->prepare(
    "INSERT INTO company_email_events (company_email_id, recipient, event, url, reason, sg_message_id, occurred_at)
     VALUES (?,?,?,?,?,?,?)"
);

foreach ($events as $evt) {
    if (!is_array($evt)) continue;
    $companyEmailId = (int)($evt['company_email_id'] ?? 0);
    if ($companyEmailId <= 0) continue; // not a Company Email send — ignore

    $recipient = strtolower(trim($evt['recipient'] ?? $evt['email'] ?? ''));
    $event     = trim($evt['event'] ?? '');
    if ($recipient === '' || $event === '') continue;

    $occurredAt = isset($evt['timestamp']) ? gmdate('Y-m-d H:i:s', (int)$evt['timestamp']) : gmdate('Y-m-d H:i:s');

    $ins->execute([
        $companyEmailId,
        $recipient,
        $event,
        trim($evt['url'] ?? ''),
        trim($evt['reason'] ?? $evt['response'] ?? ''),
        trim($evt['sg_message_id'] ?? ''),
        $occurredAt,
    ]);
}

json_out(['ok'=>true]);
