<?php
/**
 * Listing Intel — Nightly automated sync
 *
 * Run via crontab:
 *   0 3 * * * /usr/bin/php /home/ec2-user/agentedge/cron/sync_listing_intel.php >> /home/ec2-user/agentedge/cron/sync_listing_intel.log 2>&1
 *
 * Pulls seller candidates from PropStream (company key) or each agent's
 * BatchData key.  Usage is logged to listing_intel_usage so admins can
 * calculate per-agent charges at month-end.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';

$cfg        = cfg();
$db         = local_db();
$companyKey = trim($cfg['propstream_api_key'] ?? '');
$costPerRec = (float)($cfg['listing_intel_cost_per_rec'] ?? 0.10);
$now        = date('Y-m-d H:i:s');
$period     = date('Y-m');

echo "[{$now}] Listing Intel nightly sync starting\n";

// ── Provider abstraction ─────────────────────────────────────────────────────
// Returns array of prospect arrays for a given zip code.
// PropStream and BatchData return similar shapes; normalize here.
function fetch_candidates(string $apiKey, string $provider, string $zip, int $minYears, int $maxPerZip): array {
    if ($provider === 'propstream') {
        return fetch_propstream($apiKey, $zip, $minYears, $maxPerZip);
    }
    return fetch_batchdata($apiKey, $zip, $minYears, $maxPerZip);
}

function li_seller_score(int $yearsOwned, float $equityPct, int $estValue): int {
    $s = 0;
    if ($yearsOwned >= 5 && $yearsOwned <= 9)    $s += 40;
    elseif ($yearsOwned >= 3)                     $s += 25;
    elseif ($yearsOwned > 9)                      $s += 15;
    else                                           $s += 5;
    if ($equityPct >= 60)      $s += 35;
    elseif ($equityPct >= 40)  $s += 26;
    elseif ($equityPct >= 25)  $s += 18;
    elseif ($equityPct >= 10)  $s += 10;
    else                       $s += 3;
    if ($estValue >= 600000)   $s += 15;
    elseif ($estValue >= 400000) $s += 12;
    elseif ($estValue >= 250000) $s += 9;
    elseif ($estValue >= 150000) $s += 6;
    else                         $s += 3;
    return min(100, $s);
}

function fetch_propstream(string $apiKey, string $zip, int $minYears, int $maxPerZip): array {
    // PropStream Platform API — https://platform.propstream.com/docs
    // POST /api/v1/search  (exact endpoint/schema may vary; adjust to match their docs)
    $payload = json_encode([
        'filters' => [
            'postalCode'    => $zip,
            'ownerOccupied' => true,
            'yearsOwned'    => ['min' => $minYears],
            'listedForSale' => false,
            'equityPercent' => ['min' => 15],
        ],
        'page'    => 1,
        'perPage' => $maxPerZip,
        'fields'  => ['address','owner','financial','sale','ownership'],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 30,
        'ignore_errors' => true,
        'header'        => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
    ]]);
    $raw  = @file_get_contents('https://api.propstream.com/api/v1/property/search', false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new \RuntimeException("PropStream API error for zip {$zip}: " . substr($raw ?? 'no response', 0, 120));
    if (!empty($data['error']) || !empty($data['message']) && empty($data['results'])) {
        throw new \RuntimeException("PropStream: " . ($data['message'] ?? $data['error'] ?? json_encode($data)));
    }
    return normalize_propstream($data['results'] ?? $data['data'] ?? [], $zip);
}

function fetch_batchdata(string $apiKey, string $zip, int $minYears, int $maxPerZip): array {
    $payload = json_encode([
        'requests'      => [['address' => ['zip' => $zip]]],
        'filterOptions' => ['ownerOccupied' => true, 'currentlyListedForSale' => false,
                            'ownership'     => ['yearsOwned' => ['gte' => $minYears]]],
        'resultFields'  => ['address' => true, 'owners' => true, 'financial' => true, 'sales' => true, 'ownership' => true],
        'count'         => $maxPerZip,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 30,
        'ignore_errors' => true,
        'header'        => "X-API-Key: {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
    ]]);
    $raw  = @file_get_contents('https://api.batchdata.com/api/v1/property/search', false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new \RuntimeException("BatchData API error for zip {$zip}");
    if (!empty($data['statusCode']) && $data['statusCode'] >= 400) {
        throw new \RuntimeException("BatchData " . $data['statusCode'] . ": " . ($data['message'] ?? ''));
    }
    $results = $data['results'] ?? $data['data'] ?? [];
    if (empty($results) && isset($data['requests'][0]['results'])) $results = $data['requests'][0]['results'];
    return normalize_batchdata($results, $zip);
}

function normalize_propstream(array $results, string $zip): array {
    $out = [];
    foreach ($results as $r) {
        $addr    = $r['address']  ?? [];
        $owner   = $r['owner']    ?? ($r['owners'][0] ?? []);
        $fin     = $r['financial'] ?? [];
        $sales   = $r['sale']     ?? $r['sales'] ?? [];
        $own     = $r['ownership'] ?? [];
        $phones  = $owner['phoneNumbers'] ?? $owner['phones'] ?? [];
        $emails  = $owner['emailAddresses'] ?? $owner['emails'] ?? [];
        $fullAddr = $addr['fullAddress'] ?? $addr['formattedAddress'] ?? '';
        if (!$fullAddr) continue;
        $estVal   = (int)($fin['estimatedValue'] ?? 0);
        $eqPct    = (float)($fin['equityPercent'] ?? 0);
        $yearsOwn = (int)($own['yearsOwned'] ?? 0);
        $out[] = [
            'address'        => $fullAddr,
            'city'           => $addr['city'] ?? '',
            'zip'            => $addr['zip'] ?? $zip,
            'owner_name'     => $owner['fullName'] ?? trim(($owner['firstName']??'').' '.($owner['lastName']??'')),
            'phone'          => is_array($phones) && $phones ? ($phones[0]['number'] ?? $phones[0]) : '',
            'email'          => is_array($emails) && $emails ? ($emails[0]['email']  ?? $emails[0]) : '',
            'est_value'      => $estVal,
            'purchase_price' => (int)($sales['lastSalePrice'] ?? 0),
            'purchase_date'  => substr($sales['lastSaleDate'] ?? '', 0, 10),
            'years_owned'    => $yearsOwn,
            'equity_pct'     => $eqPct,
            'skip_traced'    => (!empty($phones) || !empty($emails)) ? 1 : 0,
            'seller_score'   => li_seller_score($yearsOwn, $eqPct, $estVal),
        ];
    }
    return $out;
}

function normalize_batchdata(array $results, string $zip): array {
    $out = [];
    foreach ($results as $r) {
        $addr    = $r['address']  ?? [];
        $owners  = $r['owners']   ?? [$r['owner'] ?? []];
        if (isset($owners['fullName'])) $owners = [$owners];
        $owner   = $owners[0] ?? [];
        $fin     = $r['financial'] ?? [];
        $sales   = $r['sales']    ?? [];
        $own     = $r['ownership'] ?? [];
        $phones  = $owner['phones'] ?? [];
        $emails  = $owner['emails'] ?? [];
        $fullAddr = $addr['formattedAddress'] ?? $addr['line1'] ?? '';
        if (!$fullAddr) continue;
        $estVal   = (int)($fin['estimatedValue'] ?? 0);
        $eqPct    = (float)($fin['estimatedEquityPercent'] ?? 0);
        $yearsOwn = (int)($own['yearsOwned'] ?? 0);
        $out[] = [
            'address'        => $fullAddr,
            'city'           => $addr['city'] ?? '',
            'zip'            => $addr['zip']  ?? $zip,
            'owner_name'     => $owner['fullName'] ?? '',
            'phone'          => is_array($phones) && $phones ? ($phones[0]['number'] ?? $phones[0]) : '',
            'email'          => is_array($emails) && $emails ? ($emails[0]['email']  ?? $emails[0]) : '',
            'est_value'      => $estVal,
            'purchase_price' => (int)($sales['lastSalePrice'] ?? 0),
            'purchase_date'  => substr($sales['lastSaleDate'] ?? '', 0, 10),
            'years_owned'    => $yearsOwn,
            'equity_pct'     => $eqPct,
            'skip_traced'    => (!empty($phones) || !empty($emails)) ? 1 : 0,
            'seller_score'   => li_seller_score($yearsOwn, $eqPct, $estVal),
        ];
    }
    return $out;
}

// ── Upsert a prospect row ────────────────────────────────────────────────────
function upsert_prospect(\PDO $db, string $me, ?int $farmId, array $p): string {
    $byAddr = $db->prepare("SELECT id FROM listing_prospects WHERE agent_email=? AND address=? AND zip=?");
    $byAddr->execute([$me, $p['address'], $p['zip']]);
    $existId = $byAddr->fetchColumn() ?: null;
    if ($existId) {
        $db->prepare("UPDATE listing_prospects SET seller_score=?,est_value=?,purchase_price=?,purchase_date=?,
            years_owned=?,farm_id=COALESCE(?,farm_id),
            owner_name=CASE WHEN ?!='' THEN ? ELSE owner_name END,
            phone=CASE WHEN ?!='' THEN ? ELSE phone END,
            email=CASE WHEN ?!='' THEN ? ELSE email END,
            skip_traced=CASE WHEN ? THEN 1 ELSE skip_traced END,
            updated_at=datetime('now') WHERE id=? AND agent_email=? AND status='new'")
           ->execute([$p['seller_score'],$p['est_value'],$p['purchase_price'],$p['purchase_date'],
                      $p['years_owned'],$farmId,
                      $p['owner_name'],$p['owner_name'],
                      $p['phone'],$p['phone'],
                      $p['email'],$p['email'],
                      $p['skip_traced'],
                      $existId,$me]);
        return 'updated';
    } else {
        $db->prepare("INSERT INTO listing_prospects
            (agent_email,farm_id,address,city,zip,owner_name,phone,email,source,status,
             seller_score,est_value,purchase_price,purchase_date,years_owned,skip_traced)
            VALUES (?,?,?,?,?,?,?,?,'auto','new',?,?,?,?,?,?)")
           ->execute([$me,$farmId,$p['address'],$p['city'],$p['zip'],
                      $p['owner_name'],$p['phone'],$p['email'],
                      $p['seller_score'],$p['est_value'],
                      $p['purchase_price'],$p['purchase_date'],
                      $p['years_owned'],$p['skip_traced']]);
        return 'inserted';
    }
}

// ── Main loop — iterate agents with farms ────────────────────────────────────
$agentFarms = $db->query("
    SELECT DISTINCT f.agent_email,
        GROUP_CONCAT(f.zip_codes) AS all_zip_json,
        GROUP_CONCAT(f.id)        AS farm_ids
    FROM listing_farms f
    GROUP BY f.agent_email
")->fetchAll(\PDO::FETCH_ASSOC);

$totalInserted = 0;
$totalUpdated  = 0;
$totalAgents   = 0;

foreach ($agentFarms as $row) {
    $me = $row['agent_email'];

    // Determine provider + key
    $provider = '';
    $apiKey   = '';
    if ($companyKey) {
        $provider = 'propstream';
        $apiKey   = $companyKey;
    } else {
        $kr = $db->prepare("SELECT batchdata_api_key FROM agent_extra WHERE email=?");
        $kr->execute([$me]);
        $agentKey = trim($kr->fetchColumn() ?: '');
        if (!$agentKey) {
            echo "  [{$me}] skipped — no API key configured\n";
            continue;
        }
        $provider = 'batchdata';
        $apiKey   = $agentKey;
    }

    // Collect all zip codes + map zip → farm_id
    $allZips   = [];
    $zipToFarm = [];
    $farmRows  = $db->prepare("SELECT id, zip_codes FROM listing_farms WHERE agent_email=?");
    $farmRows->execute([$me]);
    foreach ($farmRows->fetchAll(\PDO::FETCH_ASSOC) as $f) {
        foreach ((json_decode($f['zip_codes'], true) ?: []) as $z) {
            $allZips[]      = $z;
            $zipToFarm[$z]  = (int)$f['id'];
        }
    }
    $allZips = array_unique($allZips);
    if (!$allZips) continue;

    $agentInserted = 0;
    $agentUpdated  = 0;
    $agentErrors   = [];

    foreach ($allZips as $zip) {
        try {
            $prospects = fetch_candidates($apiKey, $provider, $zip, 3, 250);
            foreach ($prospects as $p) {
                $farmId = $zipToFarm[$p['zip']] ?? $zipToFarm[$zip] ?? null;
                $result = upsert_prospect($db, $me, $farmId, $p);
                if ($result === 'inserted') $agentInserted++;
                else                        $agentUpdated++;
            }
        } catch (\RuntimeException $e) {
            $agentErrors[] = $e->getMessage();
        }
    }

    // Log usage for billing
    $pulled = $agentInserted + $agentUpdated;
    if ($pulled > 0) {
        $db->prepare("INSERT INTO listing_intel_usage (agent_email, period, records_pulled) VALUES (?,?,?)")
           ->execute([$me, $period, $pulled]);
    }

    $status = $agentErrors ? ' [ERRORS: ' . implode('; ', $agentErrors) . ']' : '';
    echo "  [{$me}] {$agentInserted} new, {$agentUpdated} updated{$status}\n";

    $totalInserted += $agentInserted;
    $totalUpdated  += $agentUpdated;
    $totalAgents++;
}

$done = date('Y-m-d H:i:s');
echo "[{$done}] Done. Agents: {$totalAgents} | New: {$totalInserted} | Updated: {$totalUpdated}\n\n";
