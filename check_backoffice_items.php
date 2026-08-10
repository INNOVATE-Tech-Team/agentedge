<?php
require_once '/var/www/html/db.php';
require_once '/var/www/html/local_db.php';
echo "--- backoffice_items with Roster in label or group ---\n";
foreach (local_db()->query("SELECT * FROM backoffice_items WHERE label LIKE '%Roster%' OR group_label LIKE '%Compan%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}
echo "\n--- distinct group_labels in backoffice_items ---\n";
foreach (local_db()->query("SELECT DISTINCT group_label FROM backoffice_items")->fetchAll(PDO::FETCH_COLUMN) as $g) echo "$g\n";
