<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');
$agent = require_login();
$me    = $agent['email'];
$db    = local_db();

function je(array $d): never { echo json_encode($d); exit; }
function err(string $msg, int $code = 400): never { http_response_code($code); je(['ok' => false, 'error' => $msg]); }

// ── Retrieve agent's BatchData API key ────────────────────────────────────────
function get_batchdata_key(string $email): string {
    $r = local_db()->prepare("SELECT batchdata_api_key FROM agent_extra WHERE email=?");
    $r->execute([$email]);
    return trim($r->fetchColumn() ?: '');
}

// ── Log usage for billing ─────────────────────────────────────────────────────
function li_log_usage(\PDO $db, string $email, int $count): void {
    if ($count <= 0) return;
    $period = date('Y-m');
    $db->prepare("INSERT INTO listing_intel_usage (agent_email, period, records_pulled) VALUES (?,?,?)")
       ->execute([$email, $period, $count]);
}

// ── Zip codes to query: explicit override, or every zip across the agent's farms ──
function li_farm_zips(\PDO $db, string $email, string $overrideZip = ''): array {
    if ($overrideZip) return [$overrideZip];
    $sf = $db->prepare("SELECT zip_codes FROM listing_farms WHERE agent_email=?");
    $sf->execute([$email]);
    $zips = [];
    foreach ($sf->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach ((json_decode($json, true) ?: []) as $z) { if ($z) $zips[] = $z; }
    }
    return array_unique($zips);
}

// ── Cached Trestle OAuth token (client_credentials, cached in oh_prefs) ───────
function li_trestle_token(): string {
    $trestle_id     = trim(cfg()['trestle_client_id']     ?? '');
    $trestle_secret = trim(cfg()['trestle_client_secret'] ?? '');
    if (!$trestle_id || !$trestle_secret) return '';
    $now    = time();
    $db2    = local_db();
    $tokRow = $db2->query("SELECT value FROM oh_prefs WHERE key='trestle_token'")->fetchColumn();
    $expRow = $db2->query("SELECT value FROM oh_prefs WHERE key='trestle_token_expires'")->fetchColumn();
    $token  = ($tokRow && $expRow && (int)$expRow > $now + 60) ? $tokRow : '';
    if ($token) return $token;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 12,
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query(['client_id' => $trestle_id, 'client_secret' => $trestle_secret, 'grant_type' => 'client_credentials', 'scope' => 'api']),
        'ignore_errors' => true]]);
    $raw = @file_get_contents('https://api.cotality.com/trestle/oidc/connect/token', false, $ctx);
    $d   = $raw ? json_decode($raw, true) : [];
    $token = $d['access_token'] ?? '';
    if ($token) {
        $db2->prepare("INSERT OR REPLACE INTO oh_prefs(key,value) VALUES('trestle_token',?)")->execute([$token]);
        $db2->prepare("INSERT OR REPLACE INTO oh_prefs(key,value) VALUES('trestle_token_expires',?)")->execute([$now + (int)($d['expires_in'] ?? 3600)]);
    }
    return $token;
}

// ── Format a zip array as an OData 'in (...)' literal list ────────────────────
function li_zip_list_odata(array $zips): string {
    return implode(',', array_map(fn($z) => "'" . str_replace("'", "''", $z) . "'", $zips));
}

// ── Score a property from tax/ownership/distress signals; returns the total plus
// the individual signal rows so the caller can persist them for auditing/retuning.
// Only scores fields a real provider (Regrid, PropertyRadar) or demo seeding actually sets.
function li_compute_signals(array $p): array {
    $signals = [];
    $add = function(string $key, $value, int $points) use (&$signals) {
        $signals[] = ['key' => $key, 'value' => (string)$value, 'points' => $points];
    };

    $yearsOwned = (int)($p['years_owned'] ?? 0);
    if ($yearsOwned >= 5 && $yearsOwned <= 9)       $pts = 40;
    elseif ($yearsOwned >= 3 && $yearsOwned < 5)    $pts = 28;
    elseif ($yearsOwned >= 10 && $yearsOwned <= 14) $pts = 22;
    elseif ($yearsOwned > 14)                       $pts = 12;
    else                                             $pts = 5;
    $add('years_owned', $yearsOwned, $pts);

    $estValue = (int)($p['est_value'] ?? 0);
    if ($estValue >= 600000)      $pts = 25;
    elseif ($estValue >= 400000)  $pts = 20;
    elseif ($estValue >= 250000)  $pts = 15;
    elseif ($estValue >= 150000)  $pts = 10;
    else                           $pts = 5;
    $add('value_tier', $estValue, $pts);

    $absentee = !empty($p['absentee_owner']);
    $add('absentee_owner', $absentee ? 1 : 0, $absentee ? 20 : 0);

    $homestead = !empty($p['homestead_exemption']);
    $add('homestead_exemption', $homestead ? 1 : 0, $homestead ? -10 : 10);

    // Equity/distress signals — populated by PropertyRadar or demo seeding, not Regrid.
    $equityPct = (float)($p['equity_pct'] ?? 0);
    if ($equityPct > 0) {
        if ($equityPct >= 60)      $pts = 25;
        elseif ($equityPct >= 40)  $pts = 18;
        elseif ($equityPct >= 25)  $pts = 12;
        elseif ($equityPct >= 10)  $pts = 6;
        else                        $pts = 2;
        $add('equity_pct', $equityPct, $pts);
    }

    $taxDelinquent = !empty($p['tax_delinquent']);
    if ($taxDelinquent) $add('tax_delinquent', 1, 25);

    $inForeclosure = !empty($p['in_foreclosure']);
    if ($inForeclosure) $add('in_foreclosure', 1, 30);

    $isVacant = !empty($p['is_vacant']);
    if ($isVacant) $add('is_vacant', 1, 15);

    $total = min(100, max(0, array_sum(array_column($signals, 'points'))));
    return ['score' => $total, 'signals' => $signals];
}

// ── Regrid parcel/tax search for a single zip code ────────────────────────────
// Real, documented API — https://support.regrid.com/api/parcel-api-v2-endpoints
// GET /api/v2/parcels/query, token auth, fields[...][op]= query syntax, paginated
// via offset_id (the last returned feature's id).
function regrid_search_zip(string $apiKey, string $zip, int $minYearsOwned, int $maxResults): array {
    $cutoff = date('Y-m-d', strtotime("-{$minYearsOwned} years"));
    $prospects = [];
    $offsetId = null;
    $limit = min(100, $maxResults);

    while (count($prospects) < $maxResults) {
        $params = [
            'fields[szip][eq]' => $zip,
            'fields[last_ownership_transfer_date][lte]' => $cutoff,
            'limit' => $limit,
            'token' => $apiKey,
        ];
        if ($offsetId) $params['offset_id'] = $offsetId;
        $url = 'https://app.regrid.com/api/v2/parcels/query?' . http_build_query($params);

        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 30, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new \RuntimeException('Could not reach Regrid API.');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('Invalid response from Regrid API.');
        if (!empty($data['error'])) throw new \RuntimeException('Regrid error: ' . (is_string($data['error']) ? $data['error'] : json_encode($data['error'])));

        $features = $data['parcels']['features'] ?? [];
        if (empty($features)) break;

        foreach ($features as $f) {
            $props = $f['properties'] ?? [];
            $address = trim($props['address'] ?? '');
            if (!$address) continue;

            $situsCity = trim($props['scity'] ?? '');
            $situsZip  = trim($props['szip5'] ?? $props['szip'] ?? $zip);
            $mailCity  = trim($props['mail_city'] ?? '');
            $mailZip   = trim($props['mail_zip'] ?? '');
            $mailingAddress = trim(implode(', ', array_filter([
                $props['mailadd'] ?? '', $mailCity, $props['mail_state2'] ?? '', $mailZip,
            ])));

            $absentee = $mailingAddress !== '' && (
                ($mailZip && $mailZip !== $situsZip) ||
                ($mailCity && strcasecmp($mailCity, $situsCity) !== 0)
            );

            $landVal  = (float)($props['landval'] ?? 0);
            $imprVal  = (float)($props['improvval'] ?? 0);
            $estValue = (int)($props['parval'] ?? ($landVal + $imprVal));

            $transferDate = substr($props['last_ownership_transfer_date'] ?? $props['saledate'] ?? '', 0, 10);
            $yearsOwned = 0;
            if ($transferDate && ($ts = strtotime($transferDate)) !== false) {
                $yearsOwned = max(0, (int)floor((time() - $ts) / (365.25 * 86400)));
            }

            $prospects[] = [
                'address'             => $address,
                'city'                => $situsCity,
                'zip'                 => $situsZip,
                'owner_name'          => trim($props['owner'] ?? ''),
                'mailing_address'     => $mailingAddress,
                'absentee_owner'      => $absentee ? 1 : 0,
                'homestead_exemption' => !empty($props['homestead_exemption']) ? 1 : 0,
                'est_value'           => $estValue,
                'purchase_price'      => (int)($props['saleprice'] ?? 0),
                'purchase_date'       => substr($props['saledate'] ?? '', 0, 10),
                'years_owned'         => $yearsOwned,
                'regrid_ll_uuid'      => (string)($props['ll_uuid'] ?? ''),
                'phone'               => '',
                'email'               => '',
                'skip_traced'         => 0,
            ];
            if (count($prospects) >= $maxResults) break;
        }

        if (count($features) < $limit) break;
        $offsetId = end($features)['id'] ?? null;
        if (!$offsetId) break;
    }

    foreach ($prospects as &$p) {
        $p['seller_score'] = li_compute_signals($p)['score'];
    }
    unset($p);

    return $prospects;
}

// ── GET requests ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_outreach') {
        $pid = (int)($_GET['prospect_id'] ?? 0);
        if (!$pid) err('Missing prospect_id');
        $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
        $s->execute([$pid, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $s = $db->prepare("SELECT * FROM listing_outreach WHERE prospect_id=? ORDER BY logged_at DESC LIMIT 50");
        $s->execute([$pid]);
        je(['ok' => true, 'items' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'sync_status') {
        $sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND status != 'dead'"); $sc->execute([$me]); $total = (int)$sc->fetchColumn();
        $sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND skip_traced=0 AND status != 'dead'"); $sc->execute([$me]); $needsTrace = (int)$sc->fetchColumn();
        $sc = $db->prepare("SELECT MAX(updated_at) FROM listing_prospects WHERE agent_email=? AND source='auto'"); $sc->execute([$me]); $lastSync = $sc->fetchColumn() ?: null;
        $regridKey = trim(cfg()['regrid_api_key'] ?? '');
        // Usage this month
        $period = date('Y-m');
        $sc = $db->prepare("SELECT COALESCE(SUM(records_pulled),0) FROM listing_intel_usage WHERE agent_email=? AND period=?");
        $sc->execute([$me, $period]);
        $monthlyPulled = (int)$sc->fetchColumn();
        $costPerRec = (float)(cfg()['listing_intel_cost_per_rec'] ?? 0.10);
        je(['ok' => true, 'total' => $total, 'needs_trace' => $needsTrace, 'last_sync' => $lastSync,
            'has_api_key'    => $regridKey !== '',
            'provider'       => $regridKey !== '' ? 'regrid' : 'none',
            'company_key'    => $regridKey !== '',
            'monthly_pulled' => $monthlyPulled,
            'monthly_cost'   => round($monthlyPulled * $costPerRec, 2),
            'cost_per_rec'   => $costPerRec,
            'period'         => $period,
        ]);
    }

    // ── CSV export for CRM import ──────────────────────────────────────────────
    if ($action === 'export_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="listing-intel-prospects-' . date('Y-m-d') . '.csv"');
        $sp = $db->prepare("
            SELECT p.*, f.name AS farm_name
            FROM listing_prospects p
            LEFT JOIN listing_farms f ON p.farm_id=f.id
            WHERE p.agent_email=? AND p.status != 'dead'
            ORDER BY p.seller_score DESC, p.updated_at DESC
        ");
        $sp->execute([$me]);
        $rows = $sp->fetchAll(PDO::FETCH_ASSOC);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['First Name','Last Name','Full Name','Phone','Email','Address','City','Zip','Farm','Seller Score','Est. Value','Purchase Price','Purchase Date','Years Owned','Status','Source','Notes','Last Contact']);
        foreach ($rows as $p) {
            $nameParts = preg_split('/\s+/', trim($p['owner_name']), 2);
            fputcsv($out, [
                $nameParts[0] ?? '',
                $nameParts[1] ?? '',
                $p['owner_name'],
                $p['phone'],
                $p['email'],
                $p['address'],
                $p['city'],
                $p['zip'],
                $p['farm_name'] ?? '',
                $p['seller_score'],
                $p['est_value'],
                $p['purchase_price'],
                $p['purchase_date'],
                $p['years_owned'],
                $p['status'],
                $p['source'],
                $p['notes'],
                $p['last_contact'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Expired pipeline — live Trestle query ─────────────────────────────────
    if ($action === 'get_expireds') {
        if (!trim(cfg()['trestle_client_id'] ?? '') || !trim(cfg()['trestle_client_secret'] ?? '')) err('Trestle MLS credentials not configured.');
        $days = max(30, min(365, (int)($_GET['days'] ?? 90)));
        $zips = li_farm_zips($db, $me, trim($_GET['zip'] ?? ''));
        if (empty($zips)) je(['ok' => true, 'listings' => []]);
        $token = li_trestle_token();
        if (!$token) err('Could not authenticate with MLS data provider.');

        $zipList = li_zip_list_odata($zips);
        $cutoff  = date('Y-m-d', strtotime("-{$days} days"));
        $filter  = "StandardStatus in ('Expired','Withdrawn') and PostalCode in ({$zipList}) and OffMarketDate ge {$cutoff}";
        $select  = 'ListingId,UnparsedAddress,City,PostalCode,ListPrice,DaysOnMarket,OffMarketDate,StandardStatus,ListAgentFullName';
        $url     = 'https://api.cotality.com/trestle/odata/Property?$filter=' . rawurlencode($filter)
                 . '&$select=' . rawurlencode($select) . '&$orderby=OffMarketDate%20desc&$top=200';

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'ignore_errors' => true]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) err('Invalid response from MLS provider.');
        if (isset($data['error'])) err('MLS error: ' . ($data['error']['message'] ?? json_encode($data['error'])));

        $listings = array_map(fn($l) => [
            'mls_number'         => $l['ListingId']        ?? '',
            'address'            => $l['UnparsedAddress']  ?? '',
            'city'               => $l['City']             ?? '',
            'zip'                => $l['PostalCode']        ?? '',
            'list_price'         => (int)($l['ListPrice']  ?? 0),
            'days_on_market'     => (int)($l['DaysOnMarket'] ?? 0),
            'expiration_date'    => substr($l['OffMarketDate'] ?? '', 0, 10),
            'status'             => $l['StandardStatus']   ?? '',
            'listing_agent_name' => $l['ListAgentFullName'] ?? '',
        ], $data['value'] ?? []);

        je(['ok' => true, 'listings' => $listings]);
    }

    // ── Active MLS listings for the map — live Trestle query ──────────────────
    if ($action === 'get_active_listings') {
        if (!trim(cfg()['trestle_client_id'] ?? '') || !trim(cfg()['trestle_client_secret'] ?? '')) err('Trestle MLS credentials not configured.');
        $zips = li_farm_zips($db, $me, trim($_GET['zip'] ?? ''));
        if (empty($zips)) je(['ok' => true, 'listings' => []]);
        $token = li_trestle_token();
        if (!$token) err('Could not authenticate with MLS data provider.');

        $zipList = li_zip_list_odata($zips);
        $filter  = "StandardStatus eq 'Active' and PostalCode in ({$zipList})";
        $select  = 'ListingId,UnparsedAddress,City,PostalCode,ListPrice,Latitude,Longitude,ListAgentFullName,StandardStatus';
        $url     = 'https://api.cotality.com/trestle/odata/Property?$filter=' . rawurlencode($filter)
                 . '&$select=' . rawurlencode($select) . '&$top=300';

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'ignore_errors' => true]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) err('Invalid response from MLS provider.');
        if (isset($data['error'])) err('MLS error: ' . ($data['error']['message'] ?? json_encode($data['error'])));

        $listings = array_values(array_filter(array_map(fn($l) => [
            'mls_number'         => $l['ListingId']        ?? '',
            'address'            => $l['UnparsedAddress']  ?? '',
            'city'               => $l['City']             ?? '',
            'zip'                => $l['PostalCode']        ?? '',
            'list_price'         => (int)($l['ListPrice']  ?? 0),
            'lat'                => (float)($l['Latitude']  ?? 0),
            'lon'                => (float)($l['Longitude'] ?? 0),
            'listing_agent_name' => $l['ListAgentFullName'] ?? '',
        ], $data['value'] ?? []), fn($l) => $l['lat'] && $l['lon']));

        je(['ok' => true, 'listings' => $listings]);
    }

    err('Unknown action');
}

// ── POST requests ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method not allowed', 405);
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) err('Invalid JSON');
$action = $body['action'] ?? '';

// ── save_api_key ───────────────────────────────────────────────────────────────
if ($action === 'save_api_key') {
    $key = trim($body['api_key'] ?? '');
    $db->prepare("INSERT INTO agent_extra (email, batchdata_api_key, updated_at)
                  VALUES (?, ?, datetime('now'))
                  ON CONFLICT(email) DO UPDATE SET batchdata_api_key=excluded.batchdata_api_key, updated_at=excluded.updated_at")
       ->execute([$me, $key]);
    je(['ok' => true]);
}

// ── save_farm ──────────────────────────────────────────────────────────────────
if ($action === 'save_farm') {
    $id    = (int)($body['id'] ?? 0);
    $name  = trim($body['name'] ?? '');
    if (!$name) err('Farm name is required');
    $state = in_array($body['state'] ?? '', ['DE','FL','GA','MA','MD','NC','NH','NJ','OH','PA','RI','SC','TN','VA']) ? $body['state'] : '';
    $zips  = array_values(array_filter(array_map('trim', (array)($body['zip_codes']  ?? []))));
    $hoods = array_values(array_filter(array_map('trim', (array)($body['neighborhoods'] ?? []))));
    $notes = trim($body['notes'] ?? '');
    if ($id) {
        $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
        $s->execute([$id, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $db->prepare("UPDATE listing_farms SET name=?,state=?,zip_codes=?,neighborhoods=?,notes=? WHERE id=? AND agent_email=?")
           ->execute([$name, $state, json_encode($zips), json_encode($hoods), $notes, $id, $me]);
        je(['ok' => true, 'id' => $id]);
    } else {
        $db->prepare("INSERT INTO listing_farms (agent_email,name,state,zip_codes,neighborhoods,notes) VALUES (?,?,?,?,?,?)")
           ->execute([$me, $name, $state, json_encode($zips), json_encode($hoods), $notes]);
        je(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

// ── delete_farm ────────────────────────────────────────────────────────────────
if ($action === 'delete_farm') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) err('Missing id');
    $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
    $s->execute([$id, $me]);
    if (!$s->fetch()) err('Not found', 404);
    $db->prepare("UPDATE listing_prospects SET farm_id=NULL WHERE farm_id=? AND agent_email=?")->execute([$id, $me]);
    $db->prepare("DELETE FROM listing_farms WHERE id=? AND agent_email=?")->execute([$id, $me]);
    je(['ok' => true]);
}

// ── save_prospect ──────────────────────────────────────────────────────────────
if ($action === 'save_prospect') {
    $id     = (int)($body['id'] ?? 0);
    $owner  = trim($body['owner_name'] ?? '');
    $addr   = trim($body['address']    ?? '');
    if (!$owner) err('Owner name is required');
    if (!$addr)  err('Address is required');
    $farmId = $body['farm_id'] ? (int)$body['farm_id'] : null;
    if ($farmId) {
        $s = $db->prepare("SELECT id FROM listing_farms WHERE id=? AND agent_email=?");
        $s->execute([$farmId, $me]);
        if (!$s->fetch()) $farmId = null;
    }
    $src    = in_array($body['source'] ?? '', ['manual','expired','fsbo','equity','auto']) ? $body['source'] : 'manual';
    $stat   = in_array($body['status'] ?? '', ['new','contacted','active','dead'])          ? $body['status'] : 'new';
    $score  = max(0, min(100, (int)($body['seller_score'] ?? 0)));
    $value  = max(0, (int)($body['est_value'] ?? 0));
    $fields = [$farmId, $owner, $addr, trim($body['city']??''), trim($body['zip']??''),
               trim($body['phone']??''), trim($body['email']??''),
               $src, $stat, $score, $value, trim($body['mls_number']??''), trim($body['notes']??'')];
    if ($id) {
        $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
        $s->execute([$id, $me]);
        if (!$s->fetch()) err('Not found', 404);
        $db->prepare("UPDATE listing_prospects SET farm_id=?,owner_name=?,address=?,city=?,zip=?,phone=?,email=?,
            source=?,status=?,seller_score=?,est_value=?,mls_number=?,notes=?,updated_at=datetime('now')
            WHERE id=? AND agent_email=?")->execute(array_merge($fields, [$id, $me]));
        je(['ok' => true, 'id' => $id]);
    } else {
        $db->prepare("INSERT INTO listing_prospects
            (agent_email,farm_id,owner_name,address,city,zip,phone,email,source,status,seller_score,est_value,mls_number,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$me], $fields));
        je(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

// ── log_outreach ───────────────────────────────────────────────────────────────
if ($action === 'log_outreach') {
    $pid = (int)($body['prospect_id'] ?? 0);
    if (!$pid) err('Missing prospect_id');
    $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
    $s->execute([$pid, $me]);
    if (!$s->fetch()) err('Not found', 404);
    $method  = in_array($body['method']  ?? '', ['call','text','email','mail','door'])                          ? $body['method']  : 'call';
    $outcome = in_array($body['outcome'] ?? '', ['no_answer','left_vm','spoke','interested','not_interested','other']) ? $body['outcome'] : 'other';
    $notes   = trim($body['notes'] ?? '');
    $db->prepare("INSERT INTO listing_outreach (prospect_id,agent_email,method,outcome,notes) VALUES (?,?,?,?,?)")
       ->execute([$pid, $me, $method, $outcome, $notes]);
    $db->prepare("UPDATE listing_prospects SET last_contact=date('now'),updated_at=datetime('now') WHERE id=? AND agent_email=?")
       ->execute([$pid, $me]);
    if (in_array($outcome, ['interested','spoke'])) {
        $db->prepare("UPDATE listing_prospects SET status='active',updated_at=datetime('now') WHERE id=? AND agent_email=? AND status='new'")
           ->execute([$pid, $me]);
    }
    je(['ok' => true]);
}

// ── sync_prospects — Regrid company-wide parcel/tax pull ──────────────────────
if ($action === 'sync_prospects') {
    $regridKey = trim(cfg()['regrid_api_key'] ?? '');
    if (!$regridKey) err('No data provider configured. Add your Regrid API key in config.php (regrid_api_key).');

    $sf = $db->prepare("SELECT zip_codes FROM listing_farms WHERE agent_email=?");
    $sf->execute([$me]);
    $allZips = [];
    foreach ($sf->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach ((json_decode($json, true) ?: []) as $z) { if ($z) $allZips[] = $z; }
    }
    $allZips = array_unique($allZips);
    if (empty($allZips)) err('No zip codes defined in your farms. Add a farm area first.');

    $minYears   = max(1, (int)($body['min_years_owned'] ?? 3));
    $maxResults = min(500, max(50, (int)($body['max_results'] ?? 250)));

    $sf = $db->prepare("SELECT id, zip_codes FROM listing_farms WHERE agent_email=?");
    $sf->execute([$me]);
    $zipToFarm = [];
    foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $f) {
        foreach ((json_decode($f['zip_codes'], true) ?: []) as $z) { $zipToFarm[$z] = $f['id']; }
    }

    $inserted = 0; $updated = 0; $errors = [];
    $byUuid = $db->prepare("SELECT id FROM listing_prospects WHERE agent_email=? AND regrid_ll_uuid=? AND regrid_ll_uuid!=''");
    $byAddr = $db->prepare("SELECT id FROM listing_prospects WHERE agent_email=? AND address=? AND zip=?");
    $delSig = $db->prepare("DELETE FROM listing_prospect_signals WHERE prospect_id=?");
    $insSig = $db->prepare("INSERT INTO listing_prospect_signals (prospect_id,signal_key,signal_value,points) VALUES (?,?,?,?)");

    foreach ($allZips as $zip) {
        try {
            $prospects = regrid_search_zip($regridKey, $zip, $minYears, $maxResults);
        } catch (\RuntimeException $e) {
            $errors[] = "ZIP {$zip}: " . $e->getMessage();
            continue;
        }

        foreach ($prospects as $p) {
            $farmId = $zipToFarm[$p['zip']] ?? $zipToFarm[$zip] ?? null;

            $existId = null;
            if ($p['regrid_ll_uuid']) {
                $byUuid->execute([$me, $p['regrid_ll_uuid']]);
                $existId = $byUuid->fetchColumn() ?: null;
            }
            if (!$existId) {
                $byAddr->execute([$me, $p['address'], $p['zip']]);
                $existId = $byAddr->fetchColumn() ?: null;
            }

            if ($existId) {
                $upd = $db->prepare("UPDATE listing_prospects SET seller_score=?,est_value=?,purchase_price=?,purchase_date=?,
                    years_owned=?,farm_id=COALESCE(?,farm_id),
                    mailing_address=?,absentee_owner=?,homestead_exemption=?,regrid_ll_uuid=?,
                    owner_name=CASE WHEN ?!='' THEN ? ELSE owner_name END,
                    updated_at=datetime('now') WHERE id=? AND agent_email=? AND status='new'");
                $upd->execute([$p['seller_score'], $p['est_value'], $p['purchase_price'], $p['purchase_date'],
                               $p['years_owned'], $farmId,
                               $p['mailing_address'], $p['absentee_owner'], $p['homestead_exemption'], $p['regrid_ll_uuid'],
                               $p['owner_name'], $p['owner_name'],
                               $existId, $me]);
                $pid = $existId;
                // Only refresh signals if the row was actually touched — a prospect the
                // agent has already moved past 'new' keeps its existing score/signals.
                if ($upd->rowCount() > 0) {
                    $updated++;
                    $delSig->execute([$pid]);
                    foreach (li_compute_signals($p)['signals'] as $sig) {
                        $insSig->execute([$pid, $sig['key'], $sig['value'], $sig['points']]);
                    }
                }
                continue;
            } else {
                $db->prepare("INSERT INTO listing_prospects
                    (agent_email,farm_id,address,city,zip,owner_name,phone,email,source,status,
                     seller_score,est_value,purchase_price,purchase_date,years_owned,skip_traced,
                     mailing_address,absentee_owner,homestead_exemption,regrid_ll_uuid)
                    VALUES (?,?,?,?,?,?,?,?,'auto','new',?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$me, $farmId, $p['address'], $p['city'], $p['zip'],
                               $p['owner_name'], $p['phone'], $p['email'],
                               $p['seller_score'], $p['est_value'],
                               $p['purchase_price'], $p['purchase_date'],
                               $p['years_owned'], $p['skip_traced'],
                               $p['mailing_address'], $p['absentee_owner'], $p['homestead_exemption'], $p['regrid_ll_uuid']]);
                $inserted++;
                $pid = (int)$db->lastInsertId();
            }

            foreach (li_compute_signals($p)['signals'] as $sig) {
                $insSig->execute([$pid, $sig['key'], $sig['value'], $sig['points']]);
            }
        }
    }

    $pulled = $inserted + $updated;
    if ($pulled > 0) li_log_usage($db, $me, $pulled);

    $result = ['ok' => true, 'inserted' => $inserted, 'updated' => $updated,
               'total' => $pulled, 'provider' => 'regrid'];
    if ($errors) $result['errors'] = $errors;
    je($result);
}

// ── bulk_update_status ─────────────────────────────────────────────────────────
if ($action === 'bulk_update_status') {
    $ids    = array_map('intval', (array)($body['ids'] ?? []));
    $status = $body['status'] ?? '';
    if (empty($ids)) err('No prospects selected');
    if (!in_array($status, ['new', 'contacted', 'active', 'dead'])) err('Invalid status');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE listing_prospects SET status=?, updated_at=datetime('now')
        WHERE agent_email=? AND id IN ($ph)");
    $stmt->execute(array_merge([$status, $me], $ids));
    je(['ok' => true, 'updated' => $stmt->rowCount()]);
}

// ── mark_skip_traced ──────────────────────────────────────────────────────────
if ($action === 'mark_skip_traced') {
    $pid = (int)($body['prospect_id'] ?? 0);
    if (!$pid) err('Missing prospect_id');
    $s = $db->prepare("SELECT id FROM listing_prospects WHERE id=? AND agent_email=?");
    $s->execute([$pid, $me]);
    if (!$s->fetch()) err('Not found', 404);
    $ownerName = trim($body['owner_name'] ?? '');
    $phone     = trim($body['phone']      ?? '');
    $email     = trim($body['email']      ?? '');
    $db->prepare("UPDATE listing_prospects SET skip_traced=1, skip_traced_at=datetime('now'),
        owner_name=CASE WHEN ?!='' THEN ? ELSE owner_name END,
        phone=CASE WHEN ?!='' THEN ? ELSE phone END,
        email=CASE WHEN ?!='' THEN ? ELSE email END,
        updated_at=datetime('now') WHERE id=? AND agent_email=?")
       ->execute([$ownerName, $ownerName, $phone, $phone, $email, $email, $pid, $me]);
    je(['ok' => true]);
}

// ── seed_demo_data — synthetic prospects for previewing the Map + rating UI ──
// Never real people. Clearly flagged (source='demo', is_demo=1) so it's obviously
// separable from real leads and fully removable via clear_demo_data.
if ($action === 'seed_demo_data') {
    $exists = $db->prepare("SELECT id FROM listing_farms WHERE agent_email=? AND is_demo=1");
    $exists->execute([$me]);
    if ($exists->fetch()) err('Demo data already exists. Clear it first.');

    // Anchor the demo farm on one of the agent's real farm zips if any, else default to Pawleys Island.
    $anchorZip = '29585';
    $sf = $db->prepare("SELECT zip_codes FROM listing_farms WHERE agent_email=? AND is_demo=0 LIMIT 1");
    $sf->execute([$me]);
    if ($row = $sf->fetchColumn()) {
        $zs = json_decode($row, true) ?: [];
        if ($zs) $anchorZip = $zs[0];
    }
    $centroids = ['29585' => [33.460, -79.130], '29576' => [33.549, -79.055]];
    [$centerLat, $centerLon] = $centroids[$anchorZip] ?? [33.460, -79.130];

    $db->prepare("INSERT INTO listing_farms (agent_email,name,state,zip_codes,neighborhoods,notes,is_demo) VALUES (?,?,?,?,?,?,1)")
       ->execute([$me, 'Demo Farm — Sample Data', 'SC', json_encode([$anchorZip]), json_encode([]),
                  'Synthetic sample data for previewing the Map and rating system — not real leads.']);
    $farmId = (int)$db->lastInsertId();

    $streetNames = ['Ocean Breeze','Marsh Hen','Live Oak','Sea Turtle','Palmetto Bluff','Egret','Heron Pointe',
                    'Willow Creek','Sandy Ridge','Spanish Moss','Tidewater','Cypress Hollow','Dune Crossing',
                    'Salt Marsh','Magnolia Grove'];
    $streetTypes = ['Ln','Ct','Dr','Way','Rd','Trail','Loop'];
    $firstNames  = ['James','Mary','Robert','Linda','Michael','Patricia','William','Jennifer','David','Susan',
                    'Richard','Karen','Thomas','Nancy','Charles','Barbara'];
    $lastNames   = ['Anderson','Thompson','Mitchell','Coleman','Sanders','Reynolds','Foster','Barnes',
                    'Simmons','Hayes','Bryant','Wallace','Perry','Fuller'];

    $insSig = $db->prepare("INSERT INTO listing_prospect_signals (prospect_id,signal_key,signal_value,points) VALUES (?,?,?,?)");
    $ins    = $db->prepare("INSERT INTO listing_prospects
        (agent_email,farm_id,owner_name,address,city,zip,phone,email,source,status,seller_score,est_value,
         years_owned,equity_pct,tax_delinquent,in_foreclosure,is_vacant,absentee_owner,homestead_exemption,
         skip_traced,lat,lon,notes)
        VALUES (?,?,?,?,?,?,?,?,'demo','new',?,?,?,?,?,?,?,?,?,?,?,?,?)");

    for ($i = 0; $i < 40; $i++) {
        $addr = mt_rand(10, 999) . ' ' . $streetNames[array_rand($streetNames)] . ' ' . $streetTypes[array_rand($streetTypes)];
        $owner = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];

        $p = [
            'years_owned'         => mt_rand(1, 28),
            'est_value'            => mt_rand(15, 90) * 10000,
            'absentee_owner'       => mt_rand(1, 100) <= 30 ? 1 : 0,
            'homestead_exemption'  => mt_rand(1, 100) <= 65 ? 1 : 0,
            'equity_pct'           => mt_rand(5, 85),
            'tax_delinquent'       => mt_rand(1, 100) <= 10 ? 1 : 0,
            'in_foreclosure'       => mt_rand(1, 100) <= 5  ? 1 : 0,
            'is_vacant'            => mt_rand(1, 100) <= 15 ? 1 : 0,
        ];
        $computed = li_compute_signals($p);
        $score  = $computed['score'];
        $traced = mt_rand(1, 100) <= 35;
        $lat = $centerLat + (mt_rand(-300, 300) / 10000);
        $lon = $centerLon + (mt_rand(-300, 300) / 10000);

        $ins->execute([$me, $farmId, $owner, $addr, 'Pawleys Island', $anchorZip,
            $traced ? '(843) 555-' . mt_rand(1000, 9999) : '',
            $traced ? strtolower(str_replace(' ', '.', $owner)) . '@example.com' : '',
            $score, $p['est_value'], $p['years_owned'], $p['equity_pct'], $p['tax_delinquent'],
            $p['in_foreclosure'], $p['is_vacant'], $p['absentee_owner'], $p['homestead_exemption'],
            $traced ? 1 : 0, $lat, $lon, 'Sample data']);
        $pid = (int)$db->lastInsertId();

        foreach ($computed['signals'] as $sig) {
            $insSig->execute([$pid, $sig['key'], $sig['value'], $sig['points']]);
        }
    }

    je(['ok' => true, 'farm_id' => $farmId, 'inserted' => 40]);
}

// ── clear_demo_data — remove everything seed_demo_data created ────────────────
if ($action === 'clear_demo_data') {
    $ids = $db->prepare("SELECT id FROM listing_prospects WHERE agent_email=? AND source='demo'");
    $ids->execute([$me]);
    $pids = $ids->fetchAll(PDO::FETCH_COLUMN);
    if ($pids) {
        $ph = implode(',', array_fill(0, count($pids), '?'));
        $db->prepare("DELETE FROM listing_prospect_signals WHERE prospect_id IN ($ph)")->execute($pids);
    }
    $db->prepare("DELETE FROM listing_prospects WHERE agent_email=? AND source='demo'")->execute([$me]);
    $db->prepare("DELETE FROM listing_farms WHERE agent_email=? AND is_demo=1")->execute([$me]);
    je(['ok' => true, 'removed' => count($pids)]);
}

err('Unknown action');
