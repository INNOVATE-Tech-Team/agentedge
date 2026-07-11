<?php
// Capture last response from finance_statements.php
// No auth needed - just returns the last log entry
 = '/tmp/stmt_last_response.txt';
if (file_exists()) {
    header('Content-Type: text/plain');
    echo file_get_contents();
} else {
    echo 'No log yet';
}
