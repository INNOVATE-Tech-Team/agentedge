<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/company_email.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!can_send_company_email()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db     = local_db();
$me     = strtolower(trim($agent['email'] ?? ''));

if ($action === 'send' || $action === 'schedule') {
    // audience/target_mc_slug arrive as JSON arrays (one or more selected each);
    // stored CSV-joined in the TEXT columns, same pattern already used for leader_types.
    $audiences   = array_values(array_unique(array_filter(array_map('trim', (array)($body['audience'] ?? [])))));
    $mcSlugs     = array_values(array_unique(array_filter(array_map('trim', (array)($body['target_mc_slug'] ?? [])))));
    $targetEmail = strtolower(trim($body['target_email'] ?? ''));
    $subject     = trim($body['subject']          ?? '');
    $html        = trim($body['body']             ?? '');
    $hasText     = trim(strip_tags($html)) !== '';
    $sendAt      = trim($body['send_at']           ?? '');

    if (!$subject || !$hasText) { echo json_encode(['ok'=>false,'error'=>'Subject and message are required']); exit; }

    $err = ce_validate_audience($audiences, $mcSlugs, $targetEmail);
    if ($err) { echo json_encode(['ok'=>false,'error'=>$err]); exit; }

    // Attachment tokens -> ids owned by this sender. A token belonging to
    // someone else (or already deleted) is silently dropped rather than erroring —
    // the compose UI never lets you attach a token you didn't just upload yourself.
    $attachTokens = array_values(array_filter(array_map('trim', (array)($body['attachment_tokens'] ?? []))));
    $attachIds = [];
    if ($attachTokens) {
        $placeholders = implode(',', array_fill(0, count($attachTokens), '?'));
        $stmt = $db->prepare("SELECT id FROM email_attachments WHERE owner_email=? AND token IN ($placeholders)");
        $stmt->execute(array_merge([$me], $attachTokens));
        $attachIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $attachIdsStr = implode(',', $attachIds);

    $recipients   = ce_resolve_recipients($audiences, $mcSlugs, $targetEmail);
    $audienceStr  = implode(',', $audiences);
    $mcSlugsStr   = implode(',', $mcSlugs);

    if ($action === 'schedule') {
        $ts = $sendAt ? strtotime($sendAt) : false;
        if ($ts === false) { echo json_encode(['ok'=>false,'error'=>'Valid send date/time required']); exit; }
        if ($ts <= time()) { echo json_encode(['ok'=>false,'error'=>'Scheduled time must be in the future']); exit; }

        $db->prepare(
            "INSERT INTO scheduled_emails (sender_email, sender_role, audience, target_mc_slug, target_email, subject, body, send_at, recipient_count, attachment_ids)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([$me, my_role(), $audienceStr, $mcSlugsStr, $targetEmail, $subject, $html, gmdate('Y-m-d H:i:s', $ts), count($recipients), $attachIdsStr]);

        echo json_encode(['ok'=>true, 'scheduled'=>true, 'recipients'=>count($recipients)]);
        exit;
    }

    $sigHtml = ce_signature_html($me, $agent['name'] ?? $me, $_SERVER['HTTP_HOST']);
    $ins = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, attachment_ids) VALUES (?, 'email', ?, ?, '', 1, ?)");
    foreach ($recipients as $r) {
        $personalized = ce_apply_merge_vars($html, $r);
        $ins->execute([$r['email'], $subject, $personalized . $sigHtml, $attachIdsStr]);
    }

    $db->prepare(
        "INSERT INTO company_emails (sender_email, sender_role, audience, target_mc_slug, subject, body, recipient_count, attachment_ids)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$me, my_role(), $audienceStr, $mcSlugsStr, $subject, $html, count($recipients), $attachIdsStr]);
    ce_log_to_agent_records($recipients, $subject, $html, $me, (int)$db->lastInsertId());

    echo json_encode(['ok'=>true, 'recipients'=>count($recipients)]);
    dispatch_notification_queue();
    exit;
}

if ($action === 'preview') {
    // Renders the draft exactly as it would send — merge vars + signature —
    // using the sender's own record as a stand-in recipient, since there's no
    // single "the" recipient when the audience can be a whole company/MC.
    $subject = trim($body['subject'] ?? '');
    $html    = trim($body['body']    ?? '');
    if (!$subject && trim(strip_tags($html)) === '') { echo json_encode(['ok'=>false,'error'=>'Write a subject and message first']); exit; }

    $recipients = ce_enrich_recipients([['email' => $me, 'name' => $agent['name'] ?? '']]);
    $sigHtml = ce_signature_html($me, $agent['name'] ?? $me, $_SERVER['HTTP_HOST']);
    $personalized = ce_apply_merge_vars($html, $recipients[0]) . $sigHtml;

    echo json_encode(['ok'=>true, 'subject'=>$subject, 'html'=>$personalized]);
    exit;
}

if ($action === 'cancel_scheduled') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $stmt = is_admin()
        ? $db->prepare("UPDATE scheduled_emails SET status='canceled' WHERE id=? AND status='pending'")
        : $db->prepare("UPDATE scheduled_emails SET status='canceled' WHERE id=? AND status='pending' AND sender_email=?");
    is_admin() ? $stmt->execute([$id]) : $stmt->execute([$id, $me]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'get_signature') {
    $s = $db->prepare("SELECT title, phone, calendar_url, website_url, use_custom, custom_html FROM email_signatures WHERE email=?");
    $s->execute([$me]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: ['title'=>'','phone'=>'','calendar_url'=>'','website_url'=>'','use_custom'=>0,'custom_html'=>''];
    $row['use_custom'] = (bool)$row['use_custom'];
    echo json_encode(array_merge(['ok'=>true], $row));
    exit;
}

if ($action === 'save_signature') {
    $title      = trim($body['title']        ?? '');
    $phone      = trim($body['phone']        ?? '');
    $cal        = trim($body['calendar_url'] ?? '');
    $web        = trim($body['website_url']  ?? '');
    $useCustom  = !empty($body['use_custom']) ? 1 : 0;
    $customHtml = trim($body['custom_html']  ?? '');
    $db->prepare(
        "INSERT INTO email_signatures (email, title, phone, calendar_url, website_url, use_custom, custom_html, updated_at)
         VALUES (?,?,?,?,?,?,?,datetime('now'))
         ON CONFLICT(email) DO UPDATE SET
           title=excluded.title, phone=excluded.phone, calendar_url=excluded.calendar_url,
           website_url=excluded.website_url, use_custom=excluded.use_custom,
           custom_html=excluded.custom_html, updated_at=excluded.updated_at"
    )->execute([$me, $title, $phone, $cal, $web, $useCustom, $customHtml]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'template_list') {
    $s = $db->prepare(
        "SELECT id, owner_email, name, subject, body_html, is_shared FROM email_templates
         WHERE owner_email=? OR is_shared=1 ORDER BY name"
    );
    $s->execute([$me]);
    echo json_encode(['ok'=>true, 'templates'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'template_save') {
    $name     = trim($body['name']      ?? '');
    $subject  = trim($body['subject']   ?? '');
    $bodyHtml = trim($body['body_html'] ?? '');
    $isShared = !empty($body['is_shared']) ? 1 : 0;
    if (!$name || !$subject || trim(strip_tags($bodyHtml)) === '') {
        echo json_encode(['ok'=>false,'error'=>'Name, subject, and message are required']);
        exit;
    }
    $db->prepare("INSERT INTO email_templates (owner_email, name, subject, body_html, is_shared) VALUES (?,?,?,?,?)")
       ->execute([$me, $name, $subject, $bodyHtml, $isShared]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'template_delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $chk = $db->prepare("SELECT owner_email FROM email_templates WHERE id=?");
    $chk->execute([$id]);
    $owner = $chk->fetchColumn();
    if ($owner !== false && $owner !== $me && !is_admin()) {
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }
    $db->prepare("DELETE FROM email_templates WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// Roster for the single-person picker's datalist. Company-wide for everyone
// with Company Email access — 'person' has no Market Center scoping.
if ($action === 'roster_list') {
    $out = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $email = strtolower(trim($a['email'] ?? ''));
        if (!$email) continue;
        $out[] = ['name' => $a['fullName'] ?? '', 'email' => $email, 'marketCenter' => $a['marketCenter'] ?? ''];
    }
    echo json_encode(['ok'=>true, 'agents'=>$out]);
    exit;
}

if ($action === 'history') {
    if (is_admin()) {
        $rows = $db->query(
            "SELECT sender_email, audience, target_mc_slug, leader_types, subject, recipient_count, sent_at
             FROM company_emails ORDER BY sent_at DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $s = $db->prepare(
            "SELECT sender_email, audience, target_mc_slug, leader_types, subject, recipient_count, sent_at
             FROM company_emails WHERE sender_email=? ORDER BY sent_at DESC LIMIT 50"
        );
        $s->execute([$me]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

if ($action === 'scheduled_list') {
    if (is_admin()) {
        $rows = $db->query(
            "SELECT id, sender_email, audience, target_mc_slug, target_email, leader_types, subject, send_at, recipient_count
             FROM scheduled_emails WHERE status='pending' ORDER BY send_at"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $s = $db->prepare(
            "SELECT id, sender_email, audience, target_mc_slug, target_email, leader_types, subject, send_at, recipient_count
             FROM scheduled_emails WHERE status='pending' AND sender_email=? ORDER BY send_at"
        );
        $s->execute([$me]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
