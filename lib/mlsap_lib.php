<?php
/**
 * AgentEdge Back Office: MLS & Boards Payables.
 * Accounts payable for MLS, REALTOR board, and MLS data feed bills only.
 * Not a general AP system — do not add non-MLS vendors or codes here.
 */

const MLSAP_ACCOUNT_TYPES       = ['MLS', 'Board', 'Board + MLS', 'Data Feed', 'Data Vendor'];
const MLSAP_BILLING_FREQUENCIES = ['Monthly', 'Quarterly', 'Semiannual', 'Annual', 'One-time'];
const MLSAP_BILL_STATUSES       = ['Open', 'Paid', 'Disputed', 'Credit Requested', 'Void'];
const MLSAP_DARWIN_STATUSES     = ['Not entered', 'Entered', 'Reconciled'];
const MLSAP_DECISIONS           = ['Keep', 'Keep lean', 'Review', 'Cut'];
const MLSAP_EXPENSE_CODES = [
    'MLS-P' => 'Office / participant MLS fee',
    'MLS-D' => 'Board dues for the DR or BIC',
    'MLS-A' => 'MLS admin and staff seats',
    'MLS-F' => 'MLS data feeds: IDX, BBO, API set-up',
    'MLS-V' => 'MLS data vendors (Relitix, Spark Statistics)',
    'MLS-X' => "An agent's own MLS or board seat we paid, billed back to the agent",
];

function mlsap_h($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function mlsap_money($v): string { return $v === null || $v === '' ? '—' : '$' . number_format((float) $v, 2); }

function mlsap_frequency_multiplier(string $freq): int {
    return match ($freq) {
        'Monthly' => 12,
        'Quarterly' => 4,
        'Semiannual' => 2,
        default => 1, // Annual, One-time
    };
}

// ── Accounts ─────────────────────────────────────────────────────────────

function mlsap_accounts(PDO $db, array $filters = []): array {
    $sql = "SELECT * FROM mlsap_accounts WHERE 1=1";
    $params = [];
    if (!empty($filters['active_only'])) $sql .= " AND active = 1";
    if (!empty($filters['state'])) { $sql .= " AND state = ?"; $params[] = $filters['state']; }
    if (!empty($filters['account_type'])) { $sql .= " AND account_type = ?"; $params[] = $filters['account_type']; }
    $sql .= " ORDER BY state, name";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mlsap_account(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM mlsap_accounts WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

const MLSAP_ACCOUNT_EDITABLE = [
    'name', 'account_type', 'state', 'market_center', 'mls_integration_id',
    'designated_realtor_or_bic', 'office_id_or_nrds', 'billing_site_url',
    'billing_frequency', 'renewal_month', 'autopay', 'owner_email',
    'prior_year_volume', 'notes', 'active',
];

function mlsap_save_account(PDO $db, array $in, ?int $id, string $by): int {
    $row = [];
    foreach (MLSAP_ACCOUNT_EDITABLE as $f) $row[$f] = $in[$f] ?? null;
    $row['name'] = trim((string) $row['name']);
    if ($row['name'] === '') throw new InvalidArgumentException('Account name is required.');
    if (!in_array($row['account_type'], MLSAP_ACCOUNT_TYPES, true)) throw new InvalidArgumentException('Invalid account type.');
    if (!in_array($row['billing_frequency'] ?: 'Annual', MLSAP_BILLING_FREQUENCIES, true)) $row['billing_frequency'] = 'Annual';
    $row['billing_frequency'] = $row['billing_frequency'] ?: 'Annual';
    $row['state'] = strtoupper(trim((string) ($row['state'] ?? '')));
    $row['market_center'] = trim((string) ($row['market_center'] ?? ''));
    $row['mls_integration_id'] = $row['mls_integration_id'] !== '' && $row['mls_integration_id'] !== null ? (int) $row['mls_integration_id'] : null;
    $row['renewal_month'] = $row['renewal_month'] !== '' && $row['renewal_month'] !== null ? max(1, min(12, (int) $row['renewal_month'])) : null;
    $row['autopay'] = !empty($row['autopay']) ? 1 : 0;
    $row['owner_email'] = strtolower(trim((string) ($row['owner_email'] ?? '')));
    $row['prior_year_volume'] = $row['prior_year_volume'] !== '' && $row['prior_year_volume'] !== null ? (float) $row['prior_year_volume'] : null;
    $row['notes'] = trim((string) ($row['notes'] ?? ''));
    $row['active'] = array_key_exists('active', $in) ? (!empty($row['active']) ? 1 : 0) : 1;
    $row['designated_realtor_or_bic'] = trim((string) ($row['designated_realtor_or_bic'] ?? ''));
    $row['office_id_or_nrds'] = trim((string) ($row['office_id_or_nrds'] ?? ''));
    $row['billing_site_url'] = trim((string) ($row['billing_site_url'] ?? ''));

    $cols = array_keys($row);
    if ($id) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $db->prepare("UPDATE mlsap_accounts SET $set, updated_at = datetime('now') WHERE id = ?")
            ->execute([...array_values($row), $id]);
        return $id;
    }
    $cols[] = 'created_by';
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $db->prepare("INSERT INTO mlsap_accounts (" . implode(', ', $cols) . ") VALUES ($placeholders)")
        ->execute([...array_values($row), $by]);
    return (int) $db->lastInsertId();
}

// ── Bills ────────────────────────────────────────────────────────────────

function mlsap_bills_for_account(PDO $db, int $accountId): array {
    $st = $db->prepare("SELECT * FROM mlsap_bills WHERE account_id = ? ORDER BY COALESCE(NULLIF(invoice_date,''), due_date) DESC, id DESC");
    $st->execute([$accountId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mlsap_bill(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM mlsap_bills WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

const MLSAP_BILL_EDITABLE = [
    'account_id', 'invoice_number', 'invoice_date', 'due_date', 'amount', 'expense_code',
    'period_start', 'period_end', 'status', 'paid_date', 'paid_amount', 'payment_method',
    'billed_back_agent_id', 'notes',
];

function mlsap_save_bill(PDO $db, array $in, ?int $id, string $by): int {
    $row = [];
    foreach (MLSAP_BILL_EDITABLE as $f) $row[$f] = $in[$f] ?? null;
    $row['account_id'] = (int) $row['account_id'];
    if (!$row['account_id'] || !mlsap_account($db, $row['account_id'])) throw new InvalidArgumentException('Valid account is required.');
    if (!array_key_exists($row['expense_code'] ?? '', MLSAP_EXPENSE_CODES)) throw new InvalidArgumentException('Invalid expense code.');
    $row['amount'] = round((float) str_replace(['$', ','], '', (string) $row['amount']), 2);
    if (!in_array($row['status'] ?: 'Open', MLSAP_BILL_STATUSES, true)) $row['status'] = 'Open';
    $row['status'] = $row['status'] ?: 'Open';
    $row['invoice_number'] = trim((string) ($row['invoice_number'] ?? ''));
    $row['invoice_date'] = trim((string) ($row['invoice_date'] ?? ''));
    $row['due_date'] = trim((string) ($row['due_date'] ?? ''));
    $row['period_start'] = trim((string) ($row['period_start'] ?? ''));
    $row['period_end'] = trim((string) ($row['period_end'] ?? ''));
    $row['paid_date'] = trim((string) ($row['paid_date'] ?? ''));
    $row['paid_amount'] = $row['paid_amount'] !== '' && $row['paid_amount'] !== null ? round((float) $row['paid_amount'], 2) : null;
    $row['payment_method'] = trim((string) ($row['payment_method'] ?? ''));
    $row['billed_back_agent_id'] = $row['billed_back_agent_id'] !== '' && $row['billed_back_agent_id'] !== null ? (int) $row['billed_back_agent_id'] : null;
    $row['notes'] = trim((string) ($row['notes'] ?? ''));

    $cols = array_keys($row);
    if ($id) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $db->prepare("UPDATE mlsap_bills SET $set, updated_at = datetime('now') WHERE id = ?")
            ->execute([...array_values($row), $id]);
    } else {
        $cols[] = 'created_by';
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $db->prepare("INSERT INTO mlsap_bills (" . implode(', ', $cols) . ") VALUES ($placeholders)")
            ->execute([...array_values($row), $by]);
        $id = (int) $db->lastInsertId();
    }
    return $id;
}

// Marks a bill Paid and, if its account is linked to a legacy mls_cost_items
// row, writes the same account's last_paid label Atlas's own
// atlas_apply('mark_paid') action writes — so Atlas's "no payment recorded"
// finding clears on its next run without a second, separate data entry.
// Also closes any currently-open Atlas finding for that legacy row directly,
// for immediate UI feedback instead of waiting for Atlas's next pass.
function mlsap_mark_paid(PDO $db, int $billId, string $paidDate, ?float $paidAmount, string $paymentMethod, string $by): void {
    $bill = mlsap_bill($db, $billId);
    if (!$bill) throw new InvalidArgumentException('Bill not found.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) throw new InvalidArgumentException('Valid paid date is required.');

    $db->prepare("UPDATE mlsap_bills SET status='Paid', paid_date=?, paid_amount=?, payment_method=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$paidDate, $paidAmount, trim($paymentMethod), $billId]);

    $account = mlsap_account($db, (int) $bill['account_id']);
    $legacyId = $account['legacy_mls_cost_item_id'] ?? null;
    if ($legacyId) {
        $label = date('M Y', strtotime($paidDate));
        try {
            $db->prepare("UPDATE mls_cost_items SET last_paid = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$label, $legacyId]);
        } catch (\Throwable $e) {}
        if (function_exists('atlas_close')) {
            try {
                $f = $db->prepare("SELECT id FROM atlas_findings WHERE target_table='mls_cost_items' AND target_id=? AND status='open'");
                $f->execute([$legacyId]);
                foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $findingId) {
                    atlas_close($db, (int) $findingId, 'done', $by, "Paid $label (via MLS & Boards Payables)");
                }
            } catch (\Throwable $e) {}
        }
    }
}

// ── Decisions ────────────────────────────────────────────────────────────

function mlsap_latest_decision(PDO $db, int $accountId): ?array {
    $st = $db->prepare("SELECT * FROM mlsap_decisions WHERE account_id = ? ORDER BY decided_at DESC, id DESC LIMIT 1");
    $st->execute([$accountId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mlsap_decision_history(PDO $db, int $accountId): array {
    $st = $db->prepare("SELECT * FROM mlsap_decisions WHERE account_id = ? ORDER BY decided_at DESC, id DESC");
    $st->execute([$accountId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mlsap_save_decision(PDO $db, int $accountId, string $decision, string $reason, string $by): int {
    if (!in_array($decision, MLSAP_DECISIONS, true)) throw new InvalidArgumentException('Invalid decision.');
    if (!mlsap_account($db, $accountId)) throw new InvalidArgumentException('Account not found.');
    $db->prepare("INSERT INTO mlsap_decisions (account_id, decision, reason, decided_by) VALUES (?, ?, ?, ?)")
        ->execute([$accountId, $decision, trim($reason), $by]);
    return (int) $db->lastInsertId();
}

function mlsap_suggested_decision(int $agentCount, ?float $priorYearVolume): string {
    if ($agentCount >= 10 || ($priorYearVolume !== null && $priorYearVolume >= 5_000_000)) return 'Keep';
    if ($agentCount >= 3) return 'Keep lean';
    if ($agentCount >= 1) return 'Review';
    return 'Cut';
}

// ── Roster / agent counts ────────────────────────────────────────────────

// market_center slug => active agent count, computed once per request and
// reused across every account (a plain GROUP BY, not N+1 per account).
function mlsap_roster_counts_by_slug(PDO $db): array {
    $counts = [];
    foreach ($db->query("SELECT market_center FROM innovate_roster WHERE active = 1 AND market_center != ''")->fetchAll(PDO::FETCH_COLUMN) as $mc) {
        $slug = slugify_mc($mc);
        $counts[$slug] = ($counts[$slug] ?? 0) + 1;
    }
    return $counts;
}

// null = "Not mapped" (no market_center set on the account) — distinct from
// a real 0, which means mapped but genuinely no active agents there.
function mlsap_agent_count(array $account, array $rosterCountsBySlug): ?int {
    $mc = trim((string) ($account['market_center'] ?? ''));
    if ($mc === '') return null;
    return $rosterCountsBySlug[slugify_mc($mc)] ?? 0;
}

// ── Cost math ────────────────────────────────────────────────────────────

// Sum of Paid bills whose paid_date falls in the trailing 12 months, else
// (no paid history in that window) the most recent bill's amount times the
// account's billing frequency, else 0.
function mlsap_annualized_cost(array $account, array $bills): float {
    $cutoff = date('Y-m-d', strtotime('-12 months'));
    $paidSum = 0.0;
    $anyPaid = false;
    foreach ($bills as $b) {
        if ($b['status'] === 'Paid' && $b['paid_date'] !== '' && $b['paid_date'] >= $cutoff) {
            $paidSum += (float) ($b['paid_amount'] ?? $b['amount']);
            $anyPaid = true;
        }
    }
    if ($anyPaid) return round($paidSum, 2);
    if ($bills) return round((float) $bills[0]['amount'] * mlsap_frequency_multiplier($account['billing_frequency']), 2);
    return 0.0;
}

function mlsap_cost_per_agent(float $annualizedCost, ?int $agentCount): ?float {
    if ($agentCount === null || $agentCount <= 0) return null;
    return round($annualizedCost / $agentCount, 2);
}

function mlsap_cost_color(?float $costPerAgent): string {
    if ($costPerAgent === null) return '';
    if ($costPerAgent < 150) return 'green';
    if ($costPerAgent <= 400) return 'yellow';
    return 'red';
}

// ── Dashboard / Darwin queue ─────────────────────────────────────────────

function mlsap_dashboard_summary(PDO $db): array {
    $today = date('Y-m-d');
    $in30 = date('Y-m-d', strtotime('+30 days'));
    $monthStart = date('Y-m-01');

    $open = $db->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt FROM mlsap_bills WHERE status='Open'")->fetch(PDO::FETCH_ASSOC);
    $overdue = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt FROM mlsap_bills WHERE status='Open' AND due_date != '' AND due_date < ?");
    $overdue->execute([$today]);
    $overdue = $overdue->fetch(PDO::FETCH_ASSOC);
    $dueSoon = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt FROM mlsap_bills WHERE status='Open' AND due_date != '' AND due_date >= ? AND due_date <= ?");
    $dueSoon->execute([$today, $in30]);
    $dueSoon = $dueSoon->fetch(PDO::FETCH_ASSOC);
    $paidThisMonth = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) amt FROM mlsap_bills WHERE status='Paid' AND paid_date >= ?");
    $paidThisMonth->execute([$monthStart]);
    $paidThisMonth = $paidThisMonth->fetch(PDO::FETCH_ASSOC);
    $notEntered = $db->query("SELECT COUNT(*) FROM mlsap_bills WHERE status='Paid' AND darwin_status='Not entered'")->fetchColumn();

    $accounts = mlsap_accounts($db, ['active_only' => true]);
    $rosterCounts = mlsap_roster_counts_by_slug($db);
    $annualSpend = 0.0;
    $reviewCutSpend = 0.0;
    foreach ($accounts as $a) {
        $bills = mlsap_bills_for_account($db, (int) $a['id']);
        $annual = mlsap_annualized_cost($a, $bills);
        $annualSpend += $annual;
        $dec = mlsap_latest_decision($db, (int) $a['id']);
        $decision = $dec['decision'] ?? mlsap_suggested_decision((int) (mlsap_agent_count($a, $rosterCounts) ?? 0), $a['prior_year_volume'] !== null ? (float) $a['prior_year_volume'] : null);
        if (in_array($decision, ['Review', 'Cut'], true)) $reviewCutSpend += $annual;
    }

    return [
        'open_count' => (int) $open['n'], 'open_amount' => (float) $open['amt'],
        'overdue_count' => (int) $overdue['n'], 'overdue_amount' => (float) $overdue['amt'],
        'due_soon_count' => (int) $dueSoon['n'], 'due_soon_amount' => (float) $dueSoon['amt'],
        'paid_this_month_count' => (int) $paidThisMonth['n'], 'paid_this_month_amount' => (float) $paidThisMonth['amt'],
        'not_entered_count' => (int) $notEntered,
        'annual_spend' => round($annualSpend, 2),
        'review_cut_spend' => round($reviewCutSpend, 2),
    ];
}

function mlsap_bills_due_soon(PDO $db, int $days = 30): array {
    $today = date('Y-m-d');
    $cutoff = date('Y-m-d', strtotime("+$days days"));
    $st = $db->prepare(
        "SELECT b.*, a.name AS account_name FROM mlsap_bills b
         JOIN mlsap_accounts a ON a.id = b.account_id
         WHERE b.status='Open' AND b.due_date != '' AND b.due_date >= ? AND b.due_date <= ?
         ORDER BY b.due_date"
    );
    $st->execute([$today, $cutoff]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mlsap_bills_overdue(PDO $db): array {
    $today = date('Y-m-d');
    $st = $db->prepare(
        "SELECT b.*, a.name AS account_name FROM mlsap_bills b
         JOIN mlsap_accounts a ON a.id = b.account_id
         WHERE b.status='Open' AND b.due_date != '' AND b.due_date < ?
         ORDER BY b.due_date"
    );
    $st->execute([$today]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mlsap_bills_open_disputed_credit(PDO $db): array {
    $st = $db->query(
        "SELECT b.*, a.name AS account_name FROM mlsap_bills b
         JOIN mlsap_accounts a ON a.id = b.account_id
         WHERE b.status IN ('Open','Disputed','Credit Requested')
         ORDER BY CASE b.status WHEN 'Open' THEN 0 WHEN 'Credit Requested' THEN 1 ELSE 2 END, b.due_date"
    );
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Every Paid bill still marked Not entered in Darwin, grouped by expense code.
function mlsap_darwin_queue(PDO $db): array {
    $st = $db->query(
        "SELECT b.*, a.name AS account_name FROM mlsap_bills b
         JOIN mlsap_accounts a ON a.id = b.account_id
         WHERE b.status='Paid' AND b.darwin_status='Not entered'
         ORDER BY b.expense_code, b.paid_date"
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $r) $grouped[$r['expense_code']][] = $r;
    return $grouped;
}

function mlsap_accounts_renewing_soon(PDO $db, int $days = 30): array {
    $thisMonth = (int) date('n');
    $months = [];
    for ($i = 0; $i <= (int) ceil($days / 30); $i++) {
        $m = (($thisMonth - 1 + $i) % 12) + 1;
        $months[] = $m;
    }
    $months = array_unique($months);
    $ph = implode(',', array_fill(0, count($months), '?'));
    $st = $db->prepare("SELECT * FROM mlsap_accounts WHERE active=1 AND renewal_month IN ($ph) ORDER BY renewal_month");
    $st->execute($months);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
