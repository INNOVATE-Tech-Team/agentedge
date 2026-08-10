<?php
require '/var/www/html/local_db.php';
require '/var/www/html/roles.php';
require '/var/www/html/lib/company_email.php';

// Simulate the 'preview' action's logic exactly, using a fake sender.
$me = 'drewbennettrealestate@gmail.com'; // real agent from earlier smoke test, has license data
$agentName = 'Andrew Bennett';
$subject = 'Test Subject';
$html = '<p>Hi {{first_name}}, you are at {{market_center}} ({{brokerage}}). License {{license_number}} {{license_state}}.</p>';

$recipients = ce_enrich_recipients([['email' => $me, 'name' => $agentName]]);
$sigHtml = ce_signature_html($me, $agentName, 'agents.innovateonline.com');
$personalized = ce_apply_merge_vars($html, $recipients[0]) . $sigHtml;

echo "Subject: $subject\n";
echo "HTML:\n$personalized\n";
