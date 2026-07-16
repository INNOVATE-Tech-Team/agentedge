<?php
// Darwin Cloud Custom API — pull-only sync (cap progress, revenue share, sales volume).
// Docs: "Darwin Custom API — INNOVATE / Agent Edge Developer Guide v1.0" (Adam Blake,
// AccountTECH, 2026-07-14). Credentials configured in config.php as 'darwin_username',
// 'darwin_access_token', 'darwin_refresh_token' (mutable state lives in darwin_auth,
// seeded from config.php on first run — see darwin_auth_row()).
//
// NOT covered by this file or the v1.0 guide: pushing a new agent INTO Darwin during
// onboarding (a separate, still-undocumented integration — see project_onboarding_workflow_spec
// step 10). This is the read side only.
//
// Auth (confirmed by Adam Blake, AccountTECH, 2026-07-15 — the v1.0 guide's "see the
// standard Darwin API documentation for header format" turned out to mean Basic auth,
// NOT Bearer): Authorization: Basic base64("{username}:{access_token}") — the access
// token substitutes for the password, never the actual account password.
//
// Refresh: POST https://api.darwin.cloud/api/auth/refresh-token, no auth header, body
// {userName, refreshToken}. AccountTECH themselves weren't sure whether refreshToken in
// the body should be base64-encoded or raw — darwin_refresh_token() tries base64 first,
// falls back to raw on failure.

const DARWIN_BASE_URL = 'https://api.darwin.cloud/api/customendpoint';

// Read (and lazily seed) the mutable auth-state row from local_db().
function darwin_auth_row(): array {
    $db = local_db();
    $row = $db->query("SELECT * FROM darwin_auth ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $c = cfg();
    $username     = trim($c['darwin_username'] ?? '');
    $accessToken  = trim($c['darwin_access_token'] ?? '');
    $refreshToken = trim($c['darwin_refresh_token'] ?? '');
    $expiresAt    = trim($c['darwin_token_expires'] ?? '');
    if ($username === '' || $accessToken === '' || $refreshToken === '') {
        throw new \RuntimeException('Darwin credentials not configured (darwin_username/darwin_access_token/darwin_refresh_token in config.php)');
    }
    $db->prepare("INSERT INTO darwin_auth (username, access_token, refresh_token, expires_at) VALUES (?,?,?,?)")
       ->execute([$username, $accessToken, $refreshToken, $expiresAt]);
    return darwin_auth_row();
}

function darwin_store_auth(string $accessToken, string $refreshToken, string $expiresAt): void {
    $row = darwin_auth_row();
    local_db()->prepare("UPDATE darwin_auth SET access_token=?, refresh_token=?, expires_at=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$accessToken, $refreshToken, $expiresAt, $row['id']]);
}

// Refresh endpoint per AccountTECH (Adam Blake, 2026-07-15): POST auth/refresh-token
// with {userName, refreshToken} — no Authorization header on this call. AccountTECH
// wasn't sure whether refreshToken in the body should be base64-encoded or raw ("if
// that errors, try the raw value") — try base64 first, fall back to raw on failure.
function darwin_refresh_token(string $refreshToken): array {
    $username = darwin_auth_row()['username'];
    $url = 'https://api.darwin.cloud/api/auth/refresh-token';

    $call = function (string $refreshValue) use ($url, $username): array {
        $payload = json_encode(['userName' => $username, 'refreshToken' => $refreshValue]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 30,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0])) {
            preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
        }
        $data = $raw ? json_decode($raw, true) : null;
        return ['code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw];
    };

    $result = $call(base64_encode($refreshToken));
    if ($result['code'] !== 200) {
        $result = $call($refreshToken); // fall back to raw, per AccountTECH's own uncertainty
    }
    if ($result['code'] !== 200 || !$result['data']) {
        throw new \RuntimeException('Darwin refresh-token exchange failed: HTTP ' . $result['code'] . ': ' . substr($result['raw'] ?: '(no response)', 0, 300));
    }

    $d = $result['data'];
    $newAccess  = $d['token'] ?? $d['accessToken'] ?? null;
    $newRefresh = $d['refreshToken'] ?? null;
    $newExpires = $d['tokenExpiration'] ?? $d['expiresAt'] ?? '';
    if (!$newAccess || !$newRefresh) {
        throw new \RuntimeException('Darwin refresh-token exchange: unexpected response shape: ' . substr($result['raw'] ?: '', 0, 300));
    }
    return ['access_token' => $newAccess, 'refresh_token' => $newRefresh, 'expires_at' => $newExpires];
}

// Returns a currently-valid access token, refreshing first if we're within the buffer
// window of the recorded expiry. If expiresAt is blank (unknown), assumes still valid —
// a 401 from darwin_request() will surface the real problem.
function darwin_get_valid_token(): string {
    $row = darwin_auth_row();
    $expiresAt = trim($row['expires_at'] ?? '');
    if ($expiresAt !== '') {
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs !== false && $expiresTs - time() < 300) {
            $new = darwin_refresh_token($row['refresh_token']);
            darwin_store_auth($new['access_token'], $new['refresh_token'], $new['expires_at']);
            return $new['access_token'];
        }
    }
    return $row['access_token'];
}

// Basic auth with username:access_token (NOT the account password) — confirmed by
// AccountTECH (Adam Blake, 2026-07-15) after the initial Bearer-token guess 401'd.
function darwin_auth_headers(): string {
    $token = darwin_get_valid_token();
    $username = darwin_auth_row()['username'];
    $encoded = base64_encode("{$username}:{$token}");
    return "Authorization: Basic {$encoded}\r\nAccept: application/json\r\n";
}

// Wrap a string/date value for use inside a Darwin filter expression, e.g.
// darwin_quote_str('Doylestown') -> "''Doylestown''"
function darwin_quote_str(string $v): string {
    return "''" . str_replace("'", "", $v) . "''";
}

function darwin_parse_currency(?string $v): float {
    if ($v === null || $v === '') return 0.0;
    return (float)str_replace(['$', ','], '', $v);
}

// One page of a Darwin custom-endpoint call. $filterExpr is the raw predicate WITHOUT
// the outer wrapping quotes (e.g. "isActiveAgent=1 and officeName=''Doylestown''") —
// this function adds the required outer double-quotes and URL-encodes the whole thing.
function darwin_request(string $viewname, string $filterExpr, int $pageSize = 200, int $pageIndex = 0): array {
    $query = http_build_query([
        'viewname' => $viewname,
        'filters'  => '"' . $filterExpr . '"',
        'pageSize' => $pageSize,
        'pageIndex'=> $pageIndex,
    ]);
    $url = DARWIN_BASE_URL . '?' . $query;

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 30,
        'header'        => darwin_auth_headers(),
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    if ($code !== 200) {
        throw new \RuntimeException("Darwin API {$viewname} HTTP {$code}: " . substr($raw ?: '(no response — check US-IP/geo-restriction)', 0, 300));
    }
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        throw new \RuntimeException("Darwin API {$viewname}: could not parse response as JSON");
    }
    // Response may be a bare array of rows, or wrapped like {"rows": [...]} / {"data": [...]}.
    if (isset($data['rows']) && is_array($data['rows'])) return $data['rows'];
    if (isset($data['data']) && is_array($data['data'])) return $data['data'];
    return $data;
}

// Pages through a viewname until a short page is returned, per the guide's recommended
// sync pattern (stable ordering by person ID, no total-count header documented).
function darwin_fetch_all(string $viewname, string $filterExpr, int $pageSize = 200): array {
    $all = [];
    $pageIndex = 0;
    while (true) {
        $rows = darwin_request($viewname, $filterExpr, $pageSize, $pageIndex);
        if (!$rows) break;
        $all = array_merge($all, $rows);
        if (count($rows) < $pageSize) break;
        $pageIndex++;
        if ($pageIndex > 1000) throw new \RuntimeException("Darwin API {$viewname}: exceeded 1000 pages — pagination likely stuck");
    }
    return $all;
}

// ── Cap progress ─────────────────────────────────────────────────────────────
function darwin_sync_cap_progress(): array {
    $db = local_db();
    $watermark = $db->query("SELECT MAX(cap_status_modify_date) AS m FROM darwin_cap_progress")->fetchColumn();
    $filter = 'isActiveAgent=1';
    if ($watermark) $filter .= ' and capStatusModifyDate>=' . darwin_quote_str($watermark);

    $rows = darwin_fetch_all('customAPI_InnovateCapProgress', $filter, 200);
    $stmt = $db->prepare("INSERT INTO darwin_cap_progress
        (agent_person_id, agent_first_name, agent_last_name, agent_name, agent_email,
         commission_plan_id, commission_plan_name, cap_amount, cap_earned, amount_left_to_cap,
         anniversary_date, anniversary_end_date, agent_start_date, terminated_date,
         recruited_by_person_id, recruited_by_name, office_id, office_name, company_id, company_name,
         is_active_agent, cap_status_modify_date, synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(agent_person_id) DO UPDATE SET
            agent_first_name=excluded.agent_first_name, agent_last_name=excluded.agent_last_name,
            agent_name=excluded.agent_name, agent_email=excluded.agent_email,
            commission_plan_id=excluded.commission_plan_id, commission_plan_name=excluded.commission_plan_name,
            cap_amount=excluded.cap_amount, cap_earned=excluded.cap_earned, amount_left_to_cap=excluded.amount_left_to_cap,
            anniversary_date=excluded.anniversary_date, anniversary_end_date=excluded.anniversary_end_date,
            agent_start_date=excluded.agent_start_date, terminated_date=excluded.terminated_date,
            recruited_by_person_id=excluded.recruited_by_person_id, recruited_by_name=excluded.recruited_by_name,
            office_id=excluded.office_id, office_name=excluded.office_name,
            company_id=excluded.company_id, company_name=excluded.company_name,
            is_active_agent=excluded.is_active_agent, cap_status_modify_date=excluded.cap_status_modify_date,
            synced_at=datetime('now')");

    foreach ($rows as $r) {
        $stmt->execute([
            (int)$r['agentPersonId'], $r['agentFirstName'] ?? '', $r['agentLastName'] ?? '',
            $r['agentName'] ?? '', $r['agentEmail'] ?? '',
            (string)($r['commissionPlanId'] ?? ''), $r['commissionPlanName'] ?? '',
            darwin_parse_currency($r['capAmount'] ?? null), darwin_parse_currency($r['capEarned'] ?? null),
            darwin_parse_currency($r['amountLeftToCap'] ?? null),
            $r['anniversaryDate'] ?? '', $r['anniversaryEndDate'] ?? '',
            $r['agentStartDate'] ?? '', $r['terminatedDate'] ?? '',
            (string)($r['recruitedByPersonId'] ?? ''), $r['recruitedByName'] ?? '',
            (string)($r['officeId'] ?? ''), $r['officeName'] ?? '',
            (string)($r['companyID'] ?? ''), $r['companyName'] ?? '',
            (int)($r['isActiveAgent'] ?? 1), $r['capStatusModifyDate'] ?? '',
        ]);
    }
    return ['synced' => count($rows), 'incremental' => (bool)$watermark];
}

// ── Revenue share (growth network) ──────────────────────────────────────────
function darwin_sync_revenue_share(): array {
    $db = local_db();
    $watermark = $db->query("SELECT MAX(rev_share_modify_date) AS m FROM darwin_revenue_share")->fetchColumn();
    $filter = "overrideRole=''Revenue Share''";
    if ($watermark) $filter .= ' and revShareModifyDate>=' . darwin_quote_str($watermark);

    $rows = darwin_fetch_all('customAPI_InnovateRevenueShare', $filter, 500);
    $stmt = $db->prepare("INSERT INTO darwin_revenue_share
        (recruiter_person_id, recruiter_name, agent_person_id, agent_name, override_role,
         ytd_amount, ytd_amount_closed_basis, ytd_amount_posted_basis, all_time_amount, all_time_paid_amount,
         voucher_count, last_override_date, has_current_override_setup, is_non_producing,
         rev_share_modify_date, company_id, synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(recruiter_person_id, agent_person_id, override_role) DO UPDATE SET
            recruiter_name=excluded.recruiter_name, agent_name=excluded.agent_name,
            ytd_amount=excluded.ytd_amount, ytd_amount_closed_basis=excluded.ytd_amount_closed_basis,
            ytd_amount_posted_basis=excluded.ytd_amount_posted_basis, all_time_amount=excluded.all_time_amount,
            all_time_paid_amount=excluded.all_time_paid_amount, voucher_count=excluded.voucher_count,
            last_override_date=excluded.last_override_date, has_current_override_setup=excluded.has_current_override_setup,
            is_non_producing=excluded.is_non_producing, rev_share_modify_date=excluded.rev_share_modify_date,
            company_id=excluded.company_id, synced_at=datetime('now')");

    foreach ($rows as $r) {
        $stmt->execute([
            (int)$r['recruiterPersonId'], $r['recruiterName'] ?? '',
            (int)$r['agentPersonId'], $r['agentName'] ?? '', $r['overrideRole'] ?? '',
            (float)($r['ytdAmount'] ?? 0), (float)($r['ytdAmountClosedBasis'] ?? 0), (float)($r['ytdAmountPostedBasis'] ?? 0),
            (float)($r['allTimeAmount'] ?? 0), (float)($r['allTimePaidAmount'] ?? 0),
            (int)($r['voucherCount'] ?? 0), $r['lastOverrideDate'] ?? '',
            (int)($r['hasCurrentOverrideSetup'] ?? 1), (int)($r['isNonProducing'] ?? 0),
            $r['revShareModifyDate'] ?? '', (string)($r['companyID'] ?? ''),
        ]);
    }
    return ['synced' => count($rows), 'incremental' => (bool)$watermark];
}

// ── Sales volume ─────────────────────────────────────────────────────────────
function darwin_sync_sales_volume(): array {
    $db = local_db();
    $watermark = $db->query("SELECT MAX(volume_modify_date) AS m FROM darwin_sales_volume")->fetchColumn();
    $filter = $watermark ? 'volumeModifyDate>=' . darwin_quote_str($watermark) : '1=1';

    $rows = darwin_fetch_all('customAPI_InnovateSalesVolume', $filter, 200);
    $stmt = $db->prepare("INSERT INTO darwin_sales_volume
        (agent_person_id, agent_name, ytd_sales_volume, ytd_sales_volume_processed_basis,
         ytd_list_volume, ytd_sell_volume, ytd_transaction_count, volume_modify_date, company_id, synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(agent_person_id) DO UPDATE SET
            agent_name=excluded.agent_name, ytd_sales_volume=excluded.ytd_sales_volume,
            ytd_sales_volume_processed_basis=excluded.ytd_sales_volume_processed_basis,
            ytd_list_volume=excluded.ytd_list_volume, ytd_sell_volume=excluded.ytd_sell_volume,
            ytd_transaction_count=excluded.ytd_transaction_count, volume_modify_date=excluded.volume_modify_date,
            company_id=excluded.company_id, synced_at=datetime('now')");

    foreach ($rows as $r) {
        $stmt->execute([
            (int)$r['agentPersonId'], $r['agentName'] ?? '',
            darwin_parse_currency($r['ytdSalesVolume'] ?? null), darwin_parse_currency($r['ytdSalesVolumeProcessedBasis'] ?? null),
            darwin_parse_currency($r['ytdListVolume'] ?? null), darwin_parse_currency($r['ytdSellVolume'] ?? null),
            (float)($r['ytdTransactionCount'] ?? 0), $r['volumeModifyDate'] ?? '', (string)($r['companyID'] ?? ''),
        ]);
    }
    return ['synced' => count($rows), 'incremental' => (bool)$watermark];
}

function darwin_sync_all(): array {
    return [
        'cap_progress'   => darwin_sync_cap_progress(),
        'revenue_share'  => darwin_sync_revenue_share(),
        'sales_volume'   => darwin_sync_sales_volume(),
    ];
}
