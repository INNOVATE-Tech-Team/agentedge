<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/mlsap_lib.php';
require_once __DIR__ . '/../lib/atlas_lib.php';

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_access_mlsap()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$db = local_db();
$by = $agent['email'] ?? '';

function mlsap_account_view(PDO $db, array $a, array $rosterCounts): array {
    $bills = mlsap_bills_for_account($db, (int) $a['id']);
    $annual = mlsap_annualized_cost($a, $bills);
    $agentCount = mlsap_agent_count($a, $rosterCounts);
    $costPerAgent = mlsap_cost_per_agent($annual, $agentCount);
    $dec = mlsap_latest_decision($db, (int) $a['id']);
    $lastPaid = null;
    $nextDue = null;
    foreach ($bills as $b) {
        if ($b['status'] === 'Paid' && $b['paid_date'] !== '' && ($lastPaid === null || $b['paid_date'] > $lastPaid)) $lastPaid = $b['paid_date'];
        if ($b['status'] === 'Open' && $b['due_date'] !== '' && ($nextDue === null || $b['due_date'] < $nextDue)) $nextDue = $b['due_date'];
    }
    return $a + [
        'annualized_cost'    => $annual,
        'agent_count'        => $agentCount,
        'cost_per_agent'     => $costPerAgent,
        'cost_per_agent_color' => mlsap_cost_color($costPerAgent),
        'suggested_decision' => mlsap_suggested_decision($agentCount ?? 0, $a['prior_year_volume'] !== null ? (float) $a['prior_year_volume'] : null),
        'saved_decision'     => $dec['decision'] ?? null,
        'saved_decision_reason' => $dec['reason'] ?? null,
        'saved_decision_at'  => $dec['decided_at'] ?? null,
        'last_paid'          => $lastPaid,
        'next_due'           => $nextDue,
        'bill_count'         => count($bills),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $resource = $_GET['resource'] ?? 'accounts';

    if ($resource === 'accounts') {
        $filters = ['active_only' => !isset($_GET['all'])];
        if (!empty($_GET['state'])) $filters['state'] = strtoupper(trim($_GET['state']));
        if (!empty($_GET['account_type'])) $filters['account_type'] = $_GET['account_type'];
        $rosterCounts = mlsap_roster_counts_by_slug($db);
        $rows = array_map(fn($a) => mlsap_account_view($db, $a, $rosterCounts), mlsap_accounts($db, $filters));
        if (!empty($_GET['decision'])) {
            $rows = array_values(array_filter($rows, fn($r) => ($r['saved_decision'] ?? $r['suggested_decision']) === $_GET['decision']));
        }
        if (!empty($_GET['code'])) {
            $ids = $db->query("SELECT DISTINCT account_id FROM mlsap_bills WHERE expense_code = " . $db->quote($_GET['code']))->fetchAll(PDO::FETCH_COLUMN);
            $idSet = array_flip($ids);
            $rows = array_values(array_filter($rows, fn($r) => isset($idSet[$r['id']])));
        }
        echo json_encode(['ok' => true, 'rows' => $rows, 'expense_codes' => MLSAP_EXPENSE_CODES]);
        exit;
    }

    if ($resource === 'account') {
        $id = (int) ($_GET['id'] ?? 0);
        $a = $id ? mlsap_account($db, $id) : null;
        if (!$a) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
        $rosterCounts = mlsap_roster_counts_by_slug($db);
        echo json_encode([
            'ok' => true,
            'account' => mlsap_account_view($db, $a, $rosterCounts),
            'bills' => mlsap_bills_for_account($db, $id),
            'decisions' => mlsap_decision_history($db, $id),
        ]);
        exit;
    }

    if ($resource === 'dashboard') {
        echo json_encode([
            'ok' => true,
            'summary' => mlsap_dashboard_summary($db),
            'overdue' => mlsap_bills_overdue($db),
            'due_soon' => mlsap_bills_due_soon($db, 30),
            'not_entered' => mlsap_darwin_queue($db),
            'renewing_soon' => mlsap_accounts_renewing_soon($db, 30),
        ]);
        exit;
    }

    if ($resource === 'darwin_queue') {
        echo json_encode(['ok' => true, 'queue' => mlsap_darwin_queue($db), 'expense_codes' => MLSAP_EXPENSE_CODES]);
        exit;
    }

    if ($resource === 'darwin_queue_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mlsap-darwin-queue-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Expense Code', 'Account', 'Invoice Number', 'Amount', 'Paid Date']);
        foreach (mlsap_darwin_queue($db) as $code => $bills) {
            foreach ($bills as $b) {
                fputcsv($out, [$code, $b['account_name'], $b['invoice_number'], $b['amount'], $b['paid_date']]);
            }
        }
        exit;
    }

    if ($resource === 'bills_open_disputed_credit') {
        echo json_encode(['ok' => true, 'rows' => mlsap_bills_open_disputed_credit($db)]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown resource']);
    exit;
}

header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

try {
    switch ($action) {
        case 'save_account': {
            $id = !empty($body['id']) ? (int) $body['id'] : null;
            $newId = mlsap_save_account($db, $body, $id, $by);
            echo json_encode(['ok' => true, 'id' => $newId]);
            break;
        }
        case 'deactivate_account': {
            $id = (int) ($body['id'] ?? 0);
            if (!$id) throw new InvalidArgumentException('id required');
            $db->prepare("UPDATE mlsap_accounts SET active=0, updated_at=datetime('now') WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'save_bill': {
            $id = !empty($body['id']) ? (int) $body['id'] : null;
            $newId = mlsap_save_bill($db, $body, $id, $by);
            echo json_encode(['ok' => true, 'id' => $newId]);
            break;
        }
        case 'delete_bill': {
            $id = (int) ($body['id'] ?? 0);
            if (!$id) throw new InvalidArgumentException('id required');
            $db->prepare("DELETE FROM mlsap_bills WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'mark_paid': {
            $id = (int) ($body['id'] ?? 0);
            $paidDate = trim((string) ($body['paid_date'] ?? date('Y-m-d')));
            $paidAmount = isset($body['paid_amount']) && $body['paid_amount'] !== '' ? (float) $body['paid_amount'] : null;
            $method = (string) ($body['payment_method'] ?? '');
            mlsap_mark_paid($db, $id, $paidDate, $paidAmount, $method, $by);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'mark_darwin_entered': {
            $ids = array_map('intval', (array) ($body['ids'] ?? ($body['id'] ? [$body['id']] : [])));
            if (!$ids) throw new InvalidArgumentException('No bills selected.');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE mlsap_bills SET darwin_status='Entered', darwin_entered_by=?, darwin_entered_at=datetime('now'), updated_at=datetime('now') WHERE id IN ($ph)")
                ->execute([$by, ...$ids]);
            echo json_encode(['ok' => true, 'count' => count($ids)]);
            break;
        }
        case 'save_decision': {
            $accountId = (int) ($body['account_id'] ?? 0);
            $decision = (string) ($body['decision'] ?? '');
            $reason = (string) ($body['reason'] ?? '');
            mlsap_save_decision($db, $accountId, $decision, $reason, $by);
            echo json_encode(['ok' => true]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
