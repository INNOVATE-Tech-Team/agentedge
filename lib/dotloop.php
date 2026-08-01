<?php
// DotLoop API helper library.
// All DotLoop HTTP calls flow through here. Pages and API endpoints must never
// call file_get_contents / curl against DotLoop directly.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/notifications.php';

const DOTLOOP_API_BASE  = 'https://api-gateway.dotloop.com/public/v2';
const DOTLOOP_TOKEN_URL = 'https://auth.dotloop.com/oauth/token';

// ── Token storage ─────────────────────────────────────────────────────────────

function dotloop_get_tokens(string $email): ?array {
    $s = local_db()->prepare(
        "SELECT agent_email, profile_id, access_token, refresh_token, expires_at
         FROM dotloop_tokens WHERE agent_email = ?"
    );
    $s->execute([$email]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dotloop_save_tokens(string $email, array $data): void {
    // $data must contain: access_token, refresh_token, expires_in (or expires_at), profile_id
    $expiresAt = isset($data['expires_at'])
        ? (int)$data['expires_at']
        : time() + (int)($data['expires_in'] ?? 3600);

    local_db()->prepare(
        "INSERT OR REPLACE INTO dotloop_tokens
             (agent_email, profile_id, access_token, refresh_token, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $email,
        $data['profile_id']    ?? null,
        $data['access_token']  ?? null,
        $data['refresh_token'] ?? null,
        $expiresAt,
    ]);
}

// ── Token refresh ─────────────────────────────────────────────────────────────

/**
 * Use the stored refresh_token to obtain a new access_token.
 * Saves the updated tokens and returns the new access_token, or null on failure.
 */
function dotloop_refresh_token(string $email): ?string {
    $row = dotloop_get_tokens($email);
    if (!$row || empty($row['refresh_token'])) return null;

    $c      = cfg();
    $clientId     = $c['dotloop_client_id']     ?? '';
    $clientSecret = $c['dotloop_client_secret'] ?? '';
    if ($clientId === '' || $clientSecret === '') return null;

    $basicAuth = base64_encode($clientId . ':' . $clientSecret);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 15,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nAuthorization: Basic {$basicAuth}\r\n",
        'content'       => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ]),
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents(DOTLOOP_TOKEN_URL, false, $ctx);
    if ($raw === false) return null;

    $d = json_decode($raw, true);
    if (empty($d['access_token'])) return null;

    // Preserve profile_id from existing row; refresh_token may or may not be rotated
    $d['profile_id']    = $row['profile_id'];
    $d['refresh_token'] = $d['refresh_token'] ?? $row['refresh_token'];
    dotloop_save_tokens($email, $d);

    return $d['access_token'];
}

/**
 * Return a valid access_token for $email.
 * Auto-refreshes if the stored token expires within 60 seconds.
 * Returns null if the agent is not connected or the refresh fails.
 */
function dotloop_token(string $email): ?string {
    $row = dotloop_get_tokens($email);
    if (!$row || empty($row['access_token'])) return null;

    if ((int)$row['expires_at'] > time() + 60) {
        return $row['access_token'];
    }

    return dotloop_refresh_token($email);
}

// ── Is connected? ─────────────────────────────────────────────────────────────

function dotloop_is_connected(string $email): bool {
    $row = dotloop_get_tokens($email);
    return $row !== null && !empty($row['access_token']);
}

// ── Generic API call ──────────────────────────────────────────────────────────

/**
 * Make an authenticated request to the DotLoop API.
 *
 * @param string      $email  Agent's email (used to look up / refresh token)
 * @param string      $method GET | POST | PATCH
 * @param string      $path   e.g. '/profile/me'  (no base URL)
 * @param array|null  $body   JSON-encoded body for POST/PATCH
 *
 * @return array  ['ok' => true, 'data' => mixed]
 *             or ['ok' => false, 'error' => string, 'status' => int]
 */
function dotloop_api(string $email, string $method, string $path, ?array $body = null): array {
    $token = dotloop_token($email);
    if ($token === null) {
        return ['ok' => false, 'error' => 'Not connected to DotLoop', 'status' => 401];
    }

    $result = _dotloop_request($token, $method, $path, $body);

    // On 401, try a single token refresh and retry once
    if (!$result['ok'] && ($result['status'] ?? 0) === 401) {
        $token = dotloop_refresh_token($email);
        if ($token === null) {
            return ['ok' => false, 'error' => 'DotLoop token expired — please reconnect', 'status' => 401];
        }
        $result = _dotloop_request($token, $method, $path, $body);
    }

    // On 429, back off and retry — DotLoop's real rate limit is much
    // tighter than expected, so this is hit routinely during a full sync.
    $attempt = 0;
    while (!$result['ok'] && ($result['status'] ?? 0) === 429 && $attempt < 6) {
        $wait = $result['retry_after'] ?? (2 ** $attempt);
        sleep(max(1, (int)$wait));
        $result = _dotloop_request($token, $method, $path, $body);
        $attempt++;
    }

    return $result;
}

/** Internal: execute one HTTP call to the DotLoop API. */
function _dotloop_request(string $token, string $method, string $path, ?array $body): array {
    $url     = DOTLOOP_API_BASE . $path;
    $headers = "Authorization: Bearer {$token}\r\nAccept: application/json\r\n";

    $opts = [
        'method'        => strtoupper($method),
        'timeout'       => 20,
        'header'        => $headers,
        'ignore_errors' => true,
    ];

    if ($body !== null) {
        $opts['header'] .= "Content-Type: application/json\r\n";
        $opts['content'] = json_encode($body);
    }

    $ctx = stream_context_create(['http' => $opts]);
    $raw = @file_get_contents($url, false, $ctx);

    // Parse HTTP status (and Retry-After, for 429 backoff) from $http_response_header
    $status     = 200;
    $retryAfter = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
            if (preg_match('#^Retry-After:\s*(\d+)#i', $h, $m)) {
                $retryAfter = (int)$m[1];
            }
        }
    }

    if ($raw === false) {
        return ['ok' => false, 'error' => 'API request failed (network error)', 'status' => 0];
    }

    if ($status >= 400) {
        $errBody = json_decode($raw, true);
        $errMsg  = $errBody['message'] ?? $errBody['error'] ?? "HTTP {$status}";
        return ['ok' => false, 'error' => $errMsg, 'status' => $status, 'retry_after' => $retryAfter];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Invalid JSON response from DotLoop', 'status' => $status];
    }

    return ['ok' => true, 'data' => $data];
}

// ── Company-wide sync (shared connection) ───────────────────────────────────────
// DotLoop's per-agent individual profiles return zero loops (confirmed live) —
// all real transaction data lives on the company profile. Rather than every
// agent doing their own OAuth connect (which wouldn't show their own loops
// anyway), one shared admin connection is synced here into a local cache, and
// "My Transactions" reads from that cache filtered by participant email.

/** The AgentEdge email holding the shared admin DotLoop connection. */
function dotloop_shared_email(): string {
    $c = cfg();
    return $c['dotloop_shared_email'] ?? 'darren@innovateonline.com';
}

/**
 * Return every email that should be treated as the same person as $email when
 * matching dotloop_loop_participants — some agents get added to loops under
 * more than one address. Configure via config.php's 'dotloop_email_groups':
 * an array of email lists, e.g. [['darren@innovateonline.com', 'darren@darrenwoodard.com']].
 * Always includes $email itself. Falls back to just [$email] if unconfigured
 * or $email isn't in any group.
 */
function dotloop_email_group(string $email): array {
    $email  = strtolower(trim($email));
    $groups = cfg()['dotloop_email_groups'] ?? [];
    foreach ($groups as $group) {
        $normalized = array_values(array_unique(array_map(
            fn($e) => strtolower(trim($e)), $group
        )));
        if (in_array($email, $normalized, true)) {
            return $normalized;
        }
    }
    return [$email];
}

/** Who to notify when a loop's DotLoop "Insurance Quote Request" field is Yes. */
function dotloop_insurance_notify_email(): string {
    $c = cfg();
    return $c['carolina_insurance_notify_email'] ?? 'amanda@carolinapropertyinsurance.com';
}

/**
 * Find the "Insurance Quote Request" answer in a DotLoop loop Detail response
 * (from GET /profile/{id}/loop/{id}/detail). DotLoop nests custom Detail
 * fields under section objects, e.g. {"New Insurance Quote Request":
 * {"SC Insurance Quote Request": "Yes"}} — search every section/field rather
 * than hardcoding the exact section/field label, since it varies by state
 * (SC/NC). Returns the raw value (e.g. "Yes"/"No"), or null if not found.
 */
function dotloop_extract_insurance_quote(array $detail): ?string {
    foreach ($detail as $section) {
        if (!is_array($section)) continue;
        foreach ($section as $fieldLabel => $value) {
            if (stripos((string)$fieldLabel, 'insurance quote request') !== false) {
                return trim((string)$value);
            }
        }
    }
    return null;
}

/**
 * Email dotloop_insurance_notify_email() about a loop that just came back
 * with an Insurance Quote Request of "Yes". Pulls buyer contact info from
 * the already-synced local participant cache.
 */
function dotloop_send_insurance_quote_notification(string $loopId, string $loopName, string $loopUrl): void {
    $db   = local_db();
    $stmt = $db->prepare(
        "SELECT name, email, role FROM dotloop_loop_participants WHERE loop_id = ? AND role LIKE '%buyer%'"
    );
    $stmt->execute([$loopId]);
    $buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $buyerLines = $buyers
        ? implode('<br>', array_map(fn($b) => htmlspecialchars($b['name'] ?: $b['email']) . ' — ' . htmlspecialchars($b['email']), $buyers))
        : '(no buyer participant on file)';

    $body = "<p>A buyer on the following INNOVATE transaction requested a Carolina Property Insurance quote in DotLoop:</p>"
          . "<p><strong>" . htmlspecialchars($loopName) . "</strong></p>"
          . "<p>{$buyerLines}</p>"
          . ($loopUrl ? "<p><a href=\"" . htmlspecialchars($loopUrl) . "\">View in DotLoop</a></p>" : '');

    $c = cfg();
    send_email_sendgrid(dotloop_insurance_notify_email(), 'Insurance Quote Requested: ' . $loopName, $body, $c, true);
}

/**
 * Execute a write statement, retrying briefly on SQLite's "database is
 * locked" — Apache/mod_php workers here are long-lived, so a brief write
 * collision is common during a sync this write-heavy. Retrying a single
 * statement is far cheaper than restarting the whole multi-thousand-loop
 * sync, which is why this exists instead of only retrying at the top level.
 */
function dotloop_execute_with_retry(PDOStatement $stmt, array $params, int $maxAttempts = 15, int $delayMs = 300): bool {
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'database is locked') === false || $attempt === $maxAttempts) {
                throw $e;
            }
            usleep($delayMs * 1000);
        }
    }
    return false;
}

/**
 * Pull company loops (via the shared admin connection) for the given deal
 * stages into the local cache, along with each loop's participants.
 *
 * $sinceDate bounds the pull with DotLoop's updated_min filter — best-effort:
 * if DotLoop ignores it, the sync just takes longer, it doesn't break anything.
 *
 * Returns ['ok' => bool, 'stages' => ['STAGE' => count], 'total_loops' => N, 'errors' => [...]].
 */
function dotloop_sync_company_loops(
    array $stages = ['ACTIVE_LISTING', 'UNDER_CONTRACT', 'SOLD', 'WITHDRAWN'],
    ?string $sinceDate = null
): array {
    $email  = dotloop_shared_email();
    $tokens = dotloop_get_tokens($email);
    if (!$tokens || empty($tokens['profile_id'])) {
        return ['ok' => false, 'error' => "Shared DotLoop connection not set up for {$email}"];
    }
    $profileId = $tokens['profile_id'];
    $sinceDate = $sinceDate ?? date('Y-m-d', strtotime('-2 years'));
    $db = local_db();

    $upsertLoop = $db->prepare(
        "INSERT INTO dotloop_loops (loop_id, name, status, deal_stage, transaction_type, dl_created, dl_updated, loop_url, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
         ON CONFLICT(loop_id) DO UPDATE SET
            name=excluded.name, status=excluded.status, deal_stage=excluded.deal_stage,
            transaction_type=excluded.transaction_type, dl_created=excluded.dl_created,
            dl_updated=excluded.dl_updated, loop_url=excluded.loop_url, synced_at=excluded.synced_at"
    );
    $upsertParticipant = $db->prepare(
        "INSERT OR REPLACE INTO dotloop_loop_participants (loop_id, email, name, role) VALUES (?, ?, ?, ?)"
    );
    $clearParticipants = $db->prepare("DELETE FROM dotloop_loop_participants WHERE loop_id = ?");

    $existingStmt = $db->prepare(
        "SELECT dl_updated, insurance_quote_requested, insurance_quote_notified_at
         FROM dotloop_loops WHERE loop_id = ?"
    );
    $updateInsuranceStmt = $db->prepare(
        "UPDATE dotloop_loops SET insurance_quote_requested = ?, insurance_quote_notified_at = ? WHERE loop_id = ?"
    );

    // First run after this feature shipped: every loop's insurance field is
    // still NULL, so the per-loop check below fetches detail for all of them
    // once. Notifications stay off for that one pass — those "Yes" answers
    // are historical, not new — then this flips on for all runs after.
    $backfillDone = (bool)$db->query(
        "SELECT value FROM dotloop_sync_state WHERE key = 'insurance_quote_backfill_done'"
    )->fetchColumn();

    $summary = ['ok' => true, 'stages' => [], 'total_loops' => 0, 'errors' => []];

    foreach ($stages as $stage) {
        $page = 1;
        $seen = 0;
        while (true) {
            $path = "/profile/{$profileId}/loop?" . http_build_query([
                'batch_number' => $page,
                'batch_size'   => 100,
            ]) . '&filter=' . rawurlencode("transaction_status={$stage}")
              . '&updated_min=' . rawurlencode($sinceDate . 'T00:00:00Z');
            $result = dotloop_api($email, 'GET', $path);
            if (!$result['ok']) {
                $summary['errors'][] = "{$stage} page {$page}: " . ($result['error'] ?? 'unknown error');
                break;
            }
            $rows = $result['data']['data'] ?? [];
            if (empty($rows)) break;

            foreach ($rows as $loop) {
                $loopId = (string)($loop['id'] ?? '');
                if ($loopId === '') continue;

                $existing  = dotloop_execute_with_retry($existingStmt, [$loopId]) ? $existingStmt->fetch(PDO::FETCH_ASSOC) : null;
                $newLoopName = (string)($loop['name'] ?? '');
                $newDlUpdated = (string)($loop['updated'] ?? '');

                dotloop_execute_with_retry($upsertLoop, [
                    $loopId,
                    $newLoopName,
                    (string)($loop['status'] ?? ''),
                    $stage,
                    (string)($loop['transactionType'] ?? ''),
                    (string)($loop['created'] ?? ''),
                    $newDlUpdated,
                    (string)($loop['loopUrl'] ?? ''),
                ]);

                // Only spend an extra API call on Detail for loops that are new
                // or changed since last sync (or, on the very first run of this
                // feature, everything — see $backfillDone above).
                $needsDetailCheck = !$existing
                    || $existing['dl_updated'] !== $newDlUpdated
                    || $existing['insurance_quote_requested'] === null;

                if ($needsDetailCheck) {
                    $detailResult = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/detail");
                    if ($detailResult['ok']) {
                        $quote = dotloop_extract_insurance_quote($detailResult['data']['data'] ?? []);
                        $wasYes = $existing && strtolower((string)$existing['insurance_quote_requested']) === 'yes';
                        $isYes  = $quote !== null && strtolower($quote) === 'yes';
                        $notifiedAt = $existing['insurance_quote_notified_at'] ?? '';

                        if ($backfillDone && $isYes && !$wasYes && $notifiedAt === '') {
                            dotloop_send_insurance_quote_notification($loopId, $newLoopName, (string)($loop['loopUrl'] ?? ''));
                            $notifiedAt = date('Y-m-d H:i:s');
                        }

                        dotloop_execute_with_retry($updateInsuranceStmt, [$quote, $notifiedAt, $loopId]);
                    }
                    usleep(100000); // light throttle on the detail call above
                }

                $partResult = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/participant");
                if ($partResult['ok']) {
                    dotloop_execute_with_retry($clearParticipants, [$loopId]);
                    $participants = $partResult['data']['data'] ?? [];
                    foreach ($participants as $p) {
                        $pEmail = strtolower(trim((string)($p['email'] ?? '')));
                        if ($pEmail === '') continue;
                        dotloop_execute_with_retry($upsertParticipant, [
                            $loopId, $pEmail, (string)($p['fullName'] ?? ''), (string)($p['role'] ?? ''),
                        ]);
                    }
                }
                usleep(100000); // light throttle on the participant call above
                $seen++;
            }

            $total = (int)($result['data']['meta']['total'] ?? 0);
            if ($page * 100 >= $total) break;
            $page++;
        }
        $summary['stages'][$stage] = $seen;
        $summary['total_loops'] += $seen;
    }

    dotloop_execute_with_retry(
        $db->prepare("INSERT OR REPLACE INTO dotloop_sync_state (key, value) VALUES ('last_full_sync', datetime('now'))"), []
    );
    if (!$backfillDone) {
        dotloop_execute_with_retry(
            $db->prepare("INSERT OR REPLACE INTO dotloop_sync_state (key, value) VALUES ('insurance_quote_backfill_done', '1')"), []
        );
    }

    return $summary;
}

// ── Folder helpers ────────────────────────────────────────────────────────────

/**
 * Return all folders for a loop.
 * ['ok' => true, 'data' => [['id'=>..,'name'=>..], ...]]
 */
function dotloop_get_folders(string $email, string $profileId, string $loopId): array {
    $result = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder");
    if (!$result['ok']) return $result;
    $folders = $result['data']['data'] ?? [];
    return ['ok' => true, 'data' => $folders];
}

/**
 * Pick the best folder id for a document type ('hud' or 'check').
 * Falls back to the first available folder if no keyword match.
 */
function dotloop_pick_folder(array $folders, string $type): ?string {
    if (empty($folders)) return null;
    $keywords = $type === 'hud'
        ? ['settlement', 'hud', 'closing', 'document']
        : ['earnest', 'check', 'deposit', 'document'];
    foreach ($keywords as $kw) {
        foreach ($folders as $f) {
            if (stripos($f['name'] ?? '', $kw) !== false) return (string)$f['id'];
        }
    }
    return (string)($folders[0]['id'] ?? '');
}

/**
 * Upload a file to a DotLoop loop folder.
 * $filePath   — absolute path to the temp/stored file
 * $fileName   — original filename (shown in DotLoop)
 * $mimeType   — e.g. 'application/pdf', 'image/jpeg'
 *
 * Returns ['ok' => true, 'data' => [...]] or ['ok' => false, 'error' => ...]
 */
function dotloop_upload_document(
    string $email,
    string $profileId,
    string $loopId,
    string $folderId,
    string $filePath,
    string $fileName,
    string $mimeType
): array {
    $token = dotloop_token($email);
    if ($token === null) {
        return ['ok' => false, 'error' => 'Not connected to DotLoop', 'status' => 401];
    }

    $fileData = @file_get_contents($filePath);
    if ($fileData === false) {
        return ['ok' => false, 'error' => 'Could not read file for upload', 'status' => 0];
    }

    $boundary = '----AgentEdgeBoundary' . bin2hex(random_bytes(8));
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . addslashes($fileName) . "\"\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= $fileData;
    $body .= "\r\n--{$boundary}--\r\n";

    $url = DOTLOOP_API_BASE . "/profile/{$profileId}/loop/{$loopId}/folder/{$folderId}/document";
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 60,
        'header'        => "Authorization: Bearer {$token}\r\n"
                         . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                         . "Accept: application/json\r\n",
        'content'       => $body,
        'ignore_errors' => true,
    ]]);

    $raw    = @file_get_contents($url, false, $ctx);
    $status = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        }
    }

    // On 401, refresh and retry once
    if ($status === 401) {
        $token = dotloop_refresh_token($email);
        if ($token === null) {
            return ['ok' => false, 'error' => 'DotLoop token expired — please reconnect', 'status' => 401];
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 60,
            'header'        => "Authorization: Bearer {$token}\r\n"
                             . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                             . "Accept: application/json\r\n",
            'content'       => $body,
            'ignore_errors' => true,
        ]]);
        $raw    = @file_get_contents($url, false, $ctx);
        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
            }
        }
    }

    if ($raw === false) return ['ok' => false, 'error' => 'Upload request failed (network error)', 'status' => 0];

    if ($status >= 400) {
        $errBody = json_decode($raw, true);
        $errMsg  = $errBody['message'] ?? $errBody['error'] ?? "HTTP {$status}";
        return ['ok' => false, 'error' => $errMsg, 'status' => $status];
    }

    $data = json_decode($raw, true);
    return ['ok' => true, 'data' => is_array($data) ? $data : []];
}
