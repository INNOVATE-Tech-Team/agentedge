<?php
/**
 * Weekly MLS & Boards Payables digest. Monday 7:45 AM Eastern, to Darren and
 * Michele. Run via:
 *   docker exec agentedge php /var/www/html/cron/mlsap_weekly_digest.php
 *   docker exec agentedge php /var/www/html/cron/mlsap_weekly_digest.php darren@innovateonline.com
 * (optional recipient argument is a comma-separated override, for a test send)
 */
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/mlsap_lib.php';
require_once 'lib/notifications.php'; // send_email_sendgrid()

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

$db = local_db();
$appUrl = 'https://agents.innovateonline.com/backoffice_mlsap.php';

$queue = mlsap_darwin_queue($db);
$queueCount = array_sum(array_map('count', $queue));
$queueRows = '';
foreach ($queue as $code => $bills) {
    foreach ($bills as $b) {
        $queueRows .= '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($code) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['account_name']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['invoice_number'] ?: '-') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">$' . number_format((float) $b['amount'], 2) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['paid_date']) . '</td>'
            . '</tr>';
    }
}

$accounts = mlsap_accounts($db, ['active_only' => true]);
$rosterCounts = mlsap_roster_counts_by_slug($db);
$reviewCutRows = '';
$reviewCutCount = 0;
foreach ($accounts as $a) {
    $bills = mlsap_bills_for_account($db, (int) $a['id']);
    $agentCount = mlsap_agent_count($a, $rosterCounts);
    $dec = mlsap_latest_decision($db, (int) $a['id']);
    $decision = $dec['decision'] ?? mlsap_suggested_decision($agentCount ?? 0, $a['prior_year_volume'] !== null ? (float) $a['prior_year_volume'] : null);
    if (!in_array($decision, ['Review', 'Cut'], true)) continue;
    $reviewCutCount++;
    $annual = mlsap_annualized_cost($a, $bills);
    $reviewCutRows .= '<tr>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($a['name']) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($decision) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . ($agentCount === null ? 'Not mapped' : h($agentCount)) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">$' . number_format($annual, 2) . '</td>'
        . '</tr>';
}

$renewals = mlsap_accounts_renewing_soon($db, 30);
$renewalRows = '';
foreach ($renewals as $a) {
    $renewalRows .= '<tr>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($a['name']) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($a['state']) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($a['renewal_month']) . '</td>'
        . '</tr>';
}

$openBills = mlsap_bills_open_disputed_credit($db);
$openRows = '';
foreach ($openBills as $b) {
    $openRows .= '<tr>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['account_name']) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['invoice_number'] ?: '-') . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['status']) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">$' . number_format((float) $b['amount'], 2) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($b['due_date'] ?: '-') . '</td>'
        . '</tr>';
}

function tableHead(array $cols): string {
    $cells = implode('', array_map(fn($c) => '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #ccc;font-size:11px;text-transform:uppercase;color:#888;">' . $c . '</th>', $cols));
    return '<tr>' . $cells . '</tr>';
}

$body = '<p>Weekly summary for MLS and board payables. Full page: ' . h($appUrl) . '</p>';

$body .= '<h3 style="margin:20px 0 6px;">Classify in Darwin this week (' . $queueCount . ')</h3>';
$body .= $queueCount
    ? '<table style="border-collapse:collapse;width:100%;font-size:13px;">' . tableHead(['Code', 'Account', 'Invoice', 'Amount', 'Paid Date']) . $queueRows . '</table>'
    : '<p>Nothing waiting to be entered.</p>';

$body .= '<h3 style="margin:20px 0 6px;">Decisions needed</h3>';
$body .= '<p style="margin:0 0 6px;font-size:13px;color:#555;">Accounts marked Review or Cut (' . $reviewCutCount . '):</p>';
$body .= $reviewCutCount
    ? '<table style="border-collapse:collapse;width:100%;font-size:13px;">' . tableHead(['Account', 'Decision', 'Agents', 'Annualized Cost']) . $reviewCutRows . '</table>'
    : '<p>None right now.</p>';
$body .= '<p style="margin:14px 0 6px;font-size:13px;color:#555;">Renewals in the next 30 days (' . count($renewals) . '):</p>';
$body .= count($renewals)
    ? '<table style="border-collapse:collapse;width:100%;font-size:13px;">' . tableHead(['Account', 'State', 'Renewal Month']) . $renewalRows . '</table>'
    : '<p>None in the next 30 days.</p>';

$body .= '<h3 style="margin:20px 0 6px;">Still open (' . count($openBills) . ')</h3>';
$body .= count($openBills)
    ? '<table style="border-collapse:collapse;width:100%;font-size:13px;">' . tableHead(['Account', 'Invoice', 'Status', 'Amount', 'Due']) . $openRows . '</table>'
    : '<p>Nothing open, overdue, or credit requested.</p>';

$c = cfg();
$recipients = isset($argv[1]) && trim($argv[1]) !== ''
    ? array_map('trim', explode(',', $argv[1]))
    : ['darren@innovateonline.com', 'michele@innovateonline.com'];
$subject = 'MLS & Boards Payables: this week';
foreach ($recipients as $recipient) {
    $ok = send_email_sendgrid($recipient, $subject, $body, $c, true);
    echo ($ok ? 'sent' : 'FAILED') . ' to ' . $recipient . "\n";
}
