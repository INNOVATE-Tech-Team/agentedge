<?php
// Open House Portal — save/update a listing + its time slots.
// POST-only handler, redirects back on success/error.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/local_db.php';

$agent = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: openhouse_mine.php');
    exit;
}

$db    = local_db();
$email = strtolower(trim($agent['email']));
$name  = $agent['name'] ?? '';

$id          = (int)($_POST['id'] ?? 0);
$mls_number  = trim($_POST['mls_number']   ?? '');
$address     = trim($_POST['address']      ?? '');
$city        = trim($_POST['city']         ?? '');
$state       = trim($_POST['state']        ?? 'SC');
$zip         = trim($_POST['zip']          ?? '');
$prop_type   = trim($_POST['property_type'] ?? 'Residential');
$list_price  = (int)str_replace([',','$'], '', $_POST['list_price'] ?? '0');
$image_url   = trim($_POST['image_url']    ?? '');
$vacate      = !empty($_POST['vacate'])    ? 1 : 0;
$visible     = !empty($_POST['visible'])   ? 1 : 0;

// Allowed property types
$validTypes = ['Residential','Condo','Townhouse','Land','Commercial'];
if (!in_array($prop_type, $validTypes, true)) $prop_type = 'Residential';

// Validate required
if ($address === '' || $city === '') {
    header('Location: openhouse_add.php' . ($id ? "?id={$id}&err=validation" : '?err=validation'));
    exit;
}

if ($id > 0) {
    // Editing: verify ownership or admin
    $lst = $db->prepare("SELECT * FROM oh_listings WHERE id=?");
    $lst->execute([$id]);
    $existing = $lst->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        header('Location: openhouse_mine.php?err=notfound');
        exit;
    }
    if (strtolower($existing['listing_agent_email']) !== $email && !is_admin()) {
        header('Location: openhouse_mine.php?err=forbidden');
        exit;
    }

    // Update listing (listing_agent_email never comes from POST)
    $db->prepare("UPDATE oh_listings SET
        mls_number=?, address=?, city=?, state=?, zip=?,
        property_type=?, list_price=?, image_url=?,
        vacate=?, visible=?
        WHERE id=?")->execute([
        $mls_number, $address, $city, $state, $zip,
        $prop_type, $list_price ?: null, $image_url,
        $vacate, $visible,
        $id,
    ]);
    $listing_id = $id;
} else {
    // Insert new listing
    $ins = $db->prepare("INSERT INTO oh_listings
        (mls_number, address, city, state, zip, property_type, list_price, listing_agent_email, listing_agent_name, image_url, vacate, visible)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        $mls_number, $address, $city, $state, $zip,
        $prop_type, $list_price ?: null,
        $email, $name,
        $image_url, $vacate, $visible,
    ]);
    $listing_id = (int)$db->lastInsertId();
}

// Delete all existing slots for this listing and re-insert
$db->prepare("DELETE FROM oh_slots WHERE listing_id=?")->execute([$listing_id]);

$dates      = $_POST['dates']       ?? [];
$starts     = $_POST['start_times'] ?? [];
$ends       = $_POST['end_times']   ?? [];
$count      = min(count($dates), count($starts), count($ends));

$insSlot = $db->prepare("INSERT INTO oh_slots (listing_id, slot_date, start_time, end_time) VALUES (?,?,?,?)");
for ($i = 0; $i < $count; $i++) {
    $d = trim($dates[$i]);
    $s = trim($starts[$i]);
    $e = trim($ends[$i]);
    if ($d === '' || $s === '' || $e === '') continue;
    // Basic date/time validation
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
    if (!preg_match('/^\d{2}:\d{2}$/', $s))       continue;
    if (!preg_match('/^\d{2}:\d{2}$/', $e))       continue;
    $insSlot->execute([$listing_id, $d, $s, $e]);
}

header('Location: openhouse_mine.php?ok=saved');
exit;
