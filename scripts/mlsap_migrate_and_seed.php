<?php
/**
 * One-off: migrate mls_cost_items into mlsap_accounts/mlsap_bills, then
 * import data/mls_register_seed.csv on top. Safe to re-run — every insert
 * is guarded by a check for already-migrated/imported rows.
 *
 * Run via: docker exec agentedge php /var/www/html/scripts/mlsap_migrate_and_seed.php
 */
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/mlsap_lib.php';

$db = local_db();
$by = 'darren@innovateonline.com';

function code_to_type(string $code): string {
    return match ($code) {
        'MLS-D' => 'Board',
        'MLS-F' => 'Data Feed',
        'MLS-V' => 'Data Vendor',
        default => 'MLS', // MLS-P, MLS-A, MLS-X
    };
}

// ── Step 1: migrate mls_cost_items (if not already done) ──────────────────
$already = (int) $db->query("SELECT COUNT(*) FROM mlsap_accounts WHERE legacy_mls_cost_item_id IS NOT NULL")->fetchColumn();
if ($already > 0) {
    echo "Skipping mls_cost_items migration — $already accounts already carry a legacy_mls_cost_item_id.\n";
} else {
    $items = $db->query("SELECT * FROM mls_cost_items")->fetchAll(PDO::FETCH_ASSOC);
    $migrated = 0;
    foreach ($items as $it) {
        $type = match ($it['item_type']) {
            'Board dues' => 'Board',
            'Board + MLS dues' => 'Board + MLS',
            'MLS subscription' => 'MLS',
            'MLS staff / admin seat' => 'MLS',
            'Data feed' => 'Data Feed',
            default => 'MLS',
        };
        $accId = (int) $db->prepare("INSERT INTO mlsap_accounts
            (name, account_type, state, designated_realtor_or_bic, billing_frequency, notes, active, created_by, legacy_mls_cost_item_id)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                $it['organization'], $type, $it['state'], $it['covers'] ?? '',
                $it['frequency'] ?: 'Annual', $it['notes'] ?? '',
                $it['status'] === 'Inactive' ? 0 : 1, $by, $it['id'],
            ]) ? (int) $db->lastInsertId() : 0;
        if (!$accId) continue;
        // One Paid bill from the item's own numbers, if it has a real
        // last_paid label (blank/placeholder values skip bill creation but
        // the account itself is still migrated).
        $amount = (float) $it['amount_per_bill'] * max(1, (int) $it['bills_per_year']);
        if ($amount > 0) {
            $paidDate = '';
            if (preg_match('/^([A-Za-z]{3,9})\s+(\d{4})$/', trim((string) $it['last_paid']), $m)) {
                $ts = strtotime("1 {$m[1]} {$m[2]}");
                if ($ts) $paidDate = date('Y-m-d', $ts);
            }
            $db->prepare("INSERT INTO mlsap_bills
                (account_id, amount, expense_code, status, paid_date, paid_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $accId, $amount, 'MLS-P', $paidDate !== '' ? 'Paid' : 'Open',
                    $paidDate, $paidDate !== '' ? $amount : null,
                    'Migrated from mls_cost_items #' . $it['id'], $by,
                ]);
        }
        $migrated++;
    }
    echo "Migrated $migrated of " . count($items) . " mls_cost_items rows.\n";
}

// ── Step 2: import data/mls_register_seed.csv ──────────────────────────────
$csvPath = 'data/mls_register_seed.csv';
if (!file_exists($csvPath)) { echo "No seed CSV at $csvPath — skipping import.\n"; exit; }

$alreadySeeded = (int) $db->query("SELECT COUNT(*) FROM mlsap_accounts WHERE notes LIKE '%mls_register_seed.csv%' OR created_by = 'mls_register_seed_import'")->fetchColumn();
if ($alreadySeeded > 0) {
    echo "Skipping CSV import — $alreadySeeded accounts already carry the seed-import marker.\n";
    exit;
}

// name => mls_integrations.id, from the matching work confirmed with Darren
// (unmatched rows left unlinked per his go-ahead to link later if/when data
// starts flowing for them).
$integrationMatch = [
    'Coastal Carolinas (CCAR) board' => 1,
    'CCAR MLS (CCMLS)' => 1,
    'Pee Dee REALTORS board' => 98,
    'Pee Dee MLS' => 98,
    'CHS Regional MLS (CTAR)' => 120,
    'Charleston Trident (CTAR) board' => 113,
    'Lowcountry MLS (Hilton Head) BBO + IDX' => 112,
    'Greater Greenville MLS (GGAR)' => 6,
    'Hive MLS (Cape Fear / Brunswick)' => 110,
    'Doorify (Raleigh) IDX + BBO' => 82,
    'Canopy (Charlotte)' => 79,
    'Triad / WSRAR (Winston-Salem)' => 111,
    'Carolina Smokies board + MLS' => 78,
    'High Country MLS (Trestle)' => 81,
    'Roanoke Valley Lake Gaston' => 80,
    'Indian River (RAIRC)' => 118,
    'Bright MLS (Fredericksburg)' => null, // ambiguous — see note below
    'CVR MLS (Richmond) admin seat' => null,
    'Bright MLS (Doylestown / Bucks)' => 90,
    'Greater Lehigh Valley (GLVR) IDX' => 119,
    'Pocono Mountains (2 Spark plans)' => null,
    'Bright MLS (Delaware)' => null, // ambiguous, same BrightMLS row as above
    'Garden State MLS admin seat' => 84,
    'Monmouth Ocean (MOREMLS) IDX' => 87,
    'South Jersey Shore (SJSRMLS) IDX + BBO' => 86,
    'East Tennessee (ETAR) BBO + IDX' => 4,
    'Realtracs via Relitix (Nashville)' => 106,
    'State-Wide MLS' => 121,
    'MLS PIN' => 5,
    'PrimeMLS' => 3,
    'MLS Now (Akron / Canton)' => 88,
    'Relitix (Bright + Realtracs analytics)' => null, // genuinely standalone, no MLS integration
    // No integration record exists at all for these (confirmed with Darren,
    // unlinked is fine — can link once/if an integration record is added):
    'Brunswick County (BCAR) board' => null,
    'Beaches MLS' => null,
    'Suncoast Tampa board / Stellar' => null,
    'Fredericksburg (FAAR) board' => null,
    'Tri-County Suburban board' => null,
    'First MLS' => null,
];

$rows = array_map('str_getcsv', file($csvPath));
$header = array_shift($rows);
$imported = 0;
$seedBillsByName = []; // name => account_id, so the 3 explicit open items below can find their account

foreach ($rows as $r) {
    if (count($r) < 10 || trim($r[1]) === '') continue;
    [$state, $name, $code, $annualCost, $agents, $costPerAgent, $decision, $contact, $lastPaid, $nextStep] = $r;
    $state = trim($state);
    $priorYearVolume = null;
    if (preg_match('/\$([\d.]+)\s*M\b/i', $nextStep, $m)) $priorYearVolume = (float) $m[1] * 1_000_000;

    $accId = (int) $db->prepare("INSERT INTO mlsap_accounts
        (name, account_type, state, mls_integration_id, designated_realtor_or_bic, billing_frequency, prior_year_volume, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,1,?)")
        ->execute([
            trim($name), code_to_type(trim($code)), $state === 'All' ? '' : $state,
            $integrationMatch[trim($name)] ?? null, trim($contact), 'Annual',
            $priorYearVolume, trim($nextStep) . ' [imported from mls_register_seed.csv]', $by,
        ]) ? (int) $db->lastInsertId() : 0;
    if (!$accId) continue;
    $seedBillsByName[trim($name)] = $accId;
    $imported++;

    if (trim($decision) !== '') {
        mlsap_save_decision($db, $accId, trim($decision), 'Initial decision from mls_register_seed.csv', $by);
    }

    // Skip the generic Paid-bill creation for the two rows whose real bill
    // detail is overridden explicitly below (CHS Regional MLS, Relitix).
    if (in_array(trim($name), ['CHS Regional MLS (CTAR)', 'Relitix (Bright + Realtracs analytics)'], true)) continue;

    $lastPaid = trim($lastPaid);
    $annualCost = trim($annualCost);
    if ($lastPaid !== '' && $annualCost !== '') {
        $ts = strtotime($lastPaid);
        if ($ts) {
            $db->prepare("INSERT INTO mlsap_bills (account_id, amount, expense_code, status, paid_date, paid_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$accId, (float) $annualCost, trim($code), 'Paid', date('Y-m-d', $ts), (float) $annualCost,
                    'Imported from mls_register_seed.csv', $by]);
        }
    }
}
echo "Imported $imported accounts from $csvPath.\n";

// ── Step 3: the three explicit open items from Darren's spec ──────────────
if (isset($seedBillsByName['Pee Dee REALTORS board'])) {
    $db->prepare("INSERT INTO mlsap_bills (account_id, amount, expense_code, status, notes, created_by) VALUES (?,?,?,?,?,?)")
        ->execute([$seedBillsByName['Pee Dee REALTORS board'], 350, 'MLS-D', 'Open',
            "Open $350, not covered by the 10/1 payment. Pay it.", $by]);
    echo "Added Pee Dee REALTORS board dues bill: \$350 Open.\n";
}
if (isset($seedBillsByName['CHS Regional MLS (CTAR)'])) {
    $db->prepare("INSERT INTO mlsap_bills (account_id, amount, expense_code, status, paid_date, paid_amount, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$seedBillsByName['CHS Regional MLS (CTAR)'], 390, 'MLS-P', 'Credit Requested', '2026-09-24', 390,
            'CHS Regional MLS Q4, Kim Loizzi seat — credit requested.', $by]);
    echo "Added CHS Regional MLS Q4 bill: \$390 Credit Requested.\n";
}
if (isset($seedBillsByName['Relitix (Bright + Realtracs analytics)'])) {
    $db->prepare("INSERT INTO mlsap_bills (account_id, amount, expense_code, status, invoice_date, notes, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$seedBillsByName['Relitix (Bright + Realtracs analytics)'], 0, 'MLS-V', 'Open', '2026-10-01',
            'Renewed 10/1/2026, amount unknown. Confirm whether non-renewal notice went out.', $by]);
    echo "Added Relitix renewal bill: amount unknown, Open.\n";
}

echo "Done.\n";
