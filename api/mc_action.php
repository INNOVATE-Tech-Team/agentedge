<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/geocode.php';

header('Content-Type: application/json');
$agent = require_login();
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

if ($action === 'save') {
    $name        = trim($in['name']           ?? '');
    $state       = strtoupper(preg_replace('/[^A-Za-z]/', '', $in['state_code'] ?? ''));
    $ord         = (int)($in['sort_ord']      ?? 0);
    $editSlug    = trim($in['edit_slug']      ?? '');
    $bicEmail    = strtolower(trim($in['bic_email']       ?? ''));
    $leaderEmail = strtolower(trim($in['mc_leader_email'] ?? ''));
    $address     = trim($in['address'] ?? '');
    $city        = trim($in['city']    ?? '');
    $zip         = trim($in['zip']     ?? '');

    // On edit keep the existing slug stable — it's a permanent identifier, not derived from name.
    // Only compute a new slug when adding a brand-new MC.
    $slug = $editSlug ?: slugify_mc($name);

    if (!$name || !$slug) { echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }

    try {
        // Fetch old record before update so we can detect what changed.
        $oldRow = null;
        if ($editSlug) {
            $s = $db->prepare("SELECT name, bic_email, address, city, zip, lat, lng FROM market_centers WHERE slug=?");
            $s->execute([$editSlug]);
            $oldRow = $s->fetch(PDO::FETCH_ASSOC);
        }

        // Only re-geocode when the address actually changed (or it's a brand-new MC with an address),
        // to avoid hammering the Census API on every unrelated field edit.
        $addressChanged = !$oldRow || $oldRow['address'] !== $address || $oldRow['city'] !== $city || $oldRow['zip'] !== $zip;
        $lat = $oldRow['lat'] ?? null;
        $lng = $oldRow['lng'] ?? null;
        $geocodedAt = $oldRow['geocoded_at'] ?? '';
        $geocodeOk  = true;
        if ($addressChanged) {
            if ($address === '' && $city === '' && $zip === '') {
                $lat = null; $lng = null; $geocodedAt = '';
            } else {
                $coords = geocode_address($address, $city, $state, $zip);
                if ($coords) {
                    $lat = $coords['lat']; $lng = $coords['lng'];
                    $geocodedAt = date('Y-m-d H:i:s');
                } else {
                    $lat = null; $lng = null; $geocodedAt = '';
                    $geocodeOk = false;
                }
            }
        }

        $db->prepare(
            "INSERT INTO market_centers (slug, name, state_code, sort_ord, enabled, bic_email, mc_leader_email, address, city, zip, lat, lng, geocoded_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(slug) DO UPDATE SET
               name=excluded.name, state_code=excluded.state_code, sort_ord=excluded.sort_ord,
               bic_email=excluded.bic_email, mc_leader_email=excluded.mc_leader_email,
               address=excluded.address, city=excluded.city, zip=excluded.zip,
               lat=excluded.lat, lng=excluded.lng, geocoded_at=excluded.geocoded_at"
        )->execute([$slug, $name, $state, $ord, $bicEmail, $leaderEmail, $address, $city, $zip, $lat, $lng, $geocodedAt]);

        // Propagate display-name change to every innovate_roster row that referenced the old name.
        if ($oldRow && $oldRow['name'] !== $name) {
            $db->prepare("UPDATE innovate_roster SET market_center=? WHERE market_center=?")
               ->execute([$name, $oldRow['name']]);
        }

        // Propagate BIC change to every agent already placed in this MC.
        if ($editSlug && $oldRow && $oldRow['bic_email'] !== $bicEmail) {
            $db->prepare("UPDATE agent_roles SET bic_email=? WHERE own_mc_slug=? AND role='agent'")
               ->execute([$bicEmail, $slug]);
        }

        echo json_encode([
            'ok' => true, 'slug' => $slug, 'name' => $name,
            'state_code' => $state, 'sort_ord' => $ord,
            'bic_email' => $bicEmail, 'mc_leader_email' => $leaderEmail,
            'geocode_ok' => $geocodeOk,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $slug = trim($in['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug required']); exit; }
    $db->prepare("DELETE FROM market_centers WHERE slug=?")->execute([$slug]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'toggle') {
    $slug = trim($in['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug required']); exit; }
    $db->prepare("UPDATE market_centers SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE slug=?")->execute([$slug]);
    $stmt = $db->prepare("SELECT enabled FROM market_centers WHERE slug=?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'enabled'=>(int)($row['enabled'] ?? 0)]);
    exit;
}

if ($action === 'import') {
    $c      = cfg();
    $base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token  = $c['crm_token'] ?? '';
    $url    = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx    = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw    = @file_get_contents($url, false, $ctx);
    $roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

    $seen  = [];
    $added = 0;
    $ins   = $db->prepare(
        "INSERT OR IGNORE INTO market_centers (slug, name, state_code, sort_ord, enabled)
         VALUES (?, ?, ?, 0, 1)"
    );
    foreach ($roster as $a) {
        $mc = $a['marketCenter'] ?? '';
        if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
        if (!$mc) continue;
        $slug = slugify_mc($mc);
        if (isset($seen[$slug])) continue;
        $seen[$slug] = true;
        $state = '';
        if (preg_match('/^([A-Z]{2})\s*[-–]/', $mc, $m)) $state = $m[1];
        $ins->execute([$slug, $mc, $state]);
        if ($ins->rowCount() > 0) $added++;
    }
    echo json_encode(['ok'=>true, 'added'=>$added]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
