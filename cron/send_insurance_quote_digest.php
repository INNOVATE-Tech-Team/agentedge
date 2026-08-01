<?php
/**
 * Manually-triggered digest: email every pending (UNDER_CONTRACT) transaction
 * flagged "Yes" on DotLoop's Insurance Quote Request field, closing on or
 * after a given date, to dotloop_insurance_notify_emails().
 *
 * Run via:
 *   docker exec agentedge php /var/www/html/cron/send_insurance_quote_digest.php 2026-08-15
 *
 * (date argument defaults to today if omitted)
 */
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/dotloop.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function fmt_price(string $val): string {
    if ($val === '') return '—';
    $num = (float)str_replace([',', '$'], '', $val);
    return $num > 0 ? '$' . number_format($num) : h($val);
}

$cutoffArg = $argv[1] ?? date('Y-m-d');
$cutoff    = strtotime($cutoffArg);
if ($cutoff === false) {
    echo "Invalid date: {$cutoffArg}\n";
    exit(1);
}

$db = local_db();
$allRows = $db->query(
    "SELECT loop_id, name, loop_url, property_address, mls_number, closing_date, purchase_price
     FROM dotloop_loops
     WHERE insurance_quote_requested = 'Yes' AND deal_stage = 'UNDER_CONTRACT'"
)->fetchAll(PDO::FETCH_ASSOC);

$rows = array_values(array_filter($allRows, function ($r) use ($cutoff) {
    if (!$r['closing_date']) return false;
    $ts = strtotime($r['closing_date']);
    return $ts !== false && $ts >= $cutoff;
}));

// Sort by closing date ascending for the email
usort($rows, fn($a, $b) => strtotime($a['closing_date']) <=> strtotime($b['closing_date']));

if (!$rows) {
    echo "No pending Yes transactions closing on or after {$cutoffArg}.\n";
    exit(0);
}

$loopIds      = array_column($rows, 'loop_id');
$placeholders = implode(',', array_fill(0, count($loopIds), '?'));

$clientsByLoop = [];
$stmt = $db->prepare(
    "SELECT loop_id, name, email, phone FROM dotloop_loop_participants
     WHERE loop_id IN ({$placeholders}) AND role LIKE '%buyer%'"
);
$stmt->execute($loopIds);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $clientsByLoop[$p['loop_id']][] = $p;
}

$agentsByLoop = [];
$stmt = $db->prepare(
    "SELECT loop_id, name, email FROM dotloop_loop_participants
     WHERE loop_id IN ({$placeholders}) AND role LIKE '%agent%' AND email != ''"
);
$stmt->execute($loopIds);
$agentCandidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($agentCandidates) {
    $emails = array_values(array_unique(array_column($agentCandidates, 'email')));
    $ph     = implode(',', array_fill(0, count($emails), '?'));
    $staffRows = db_query_safe("SELECT email FROM tblstaff WHERE email IN ({$ph})", $emails);
    $innovateEmails = array_flip(array_map(fn($r) => strtolower(trim($r['email'])), $staffRows));
    foreach ($agentCandidates as $a) {
        if (isset($innovateEmails[strtolower(trim($a['email']))])) {
            $agentsByLoop[$a['loop_id']][] = $a;
        }
    }
}

$tableRows = '';
foreach ($rows as $r) {
    $clients = $clientsByLoop[$r['loop_id']] ?? [];
    $agents  = $agentsByLoop[$r['loop_id']] ?? [];
    $address = $r['property_address'] ?: ($r['name'] ?: 'Unnamed Loop');

    $clientHtml = $clients
        ? implode('<br>', array_map(
            fn($c) => h($c['name'] ?: '(no name)') . ' — ' . h($c['phone'] ?: 'no phone') . ' — ' . h($c['email'] ?: 'no email'),
            $clients
          ))
        : '—';
    $agentHtml = $agents ? implode('<br>', array_map(fn($a) => h($a['name'] ?: $a['email']), $agents)) : '—';

    $tableRows .= '<tr>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . h($address) . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . h($r['mls_number'] ?: '—') . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . $clientHtml . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . $agentHtml . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . h($r['closing_date']) . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;">' . fmt_price((string)$r['purchase_price']) . '</td>'
        . '</tr>';
}

$body = '<p>' . count($rows) . ' pending transaction(s) flagged for a Carolina Property Insurance quote, closing on or after '
      . h(date('F j, Y', $cutoff)) . ':</p>'
      . '<table style="border-collapse:collapse;font-size:13px;width:100%;">'
      . '<tr style="text-align:left;font-size:11px;text-transform:uppercase;color:#888;">'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">Address</th>'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">MLS #</th>'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">Client</th>'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">Referring Agent</th>'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">Closing</th>'
      . '<th style="padding:8px;border-bottom:2px solid #ccc;">Price</th>'
      . '</tr>'
      . $tableRows
      . '</table>';

$c = cfg();
$subject = count($rows) . ' Pending Insurance Quote Requests (closing on/after ' . date('M j, Y', $cutoff) . ')';
foreach (dotloop_insurance_notify_emails() as $recipient) {
    $ok = send_email_sendgrid($recipient, $subject, $body, $c, true);
    echo ($ok ? 'sent' : 'FAILED') . ' to ' . $recipient . "\n";
}
