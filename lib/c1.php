<?php
// Constellation1 user provisioning stub.
// Full SOAP integration requires c1_api_token + c1_api_salt in config.php.
// Returns not_configured until credentials are set.

function c1_create_user(string $firstName, string $lastName, string $email): array {
    $c     = cfg();
    $token = $c['c1_api_token'] ?? '';
    $salt  = $c['c1_api_salt']  ?? '';
    if ($token === '' || $salt === '') {
        return ['ok'=>false,'error'=>'Constellation1 credentials not configured — add c1_api_token and c1_api_salt to config.php'];
    }
    // TODO: SOAP implementation
    return ['ok'=>false,'error'=>'Constellation1 SOAP integration coming soon'];
}
