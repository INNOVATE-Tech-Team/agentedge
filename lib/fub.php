<?php
// Follow Up Boss API — agent (team member) provisioning.
// API key configured in config.php as 'fub_api_key'.
// Docs: https://docs.followupboss.com/reference/users-create

function fub_create_user(string $name, string $email): array {
    $c      = cfg();
    $apiKey = $c['fub_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'FUB API key not configured'];

    $payload = json_encode(['name'=>$name,'email'=>$email,'role'=>'Agent']);
    $auth    = base64_encode($apiKey . ':');
    $ctx = stream_context_create(['http'=>[
        'method'        => 'POST',
        'timeout'       => 15,
        'header'        => "Authorization: Basic {$auth}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents('https://api.followupboss.com/v1/users', false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    $d = $raw ? json_decode($raw, true) : null;

    if ($code === 200 || $code === 201) {
        return ['ok'=>true,'id'=>$d['id']??null];
    }
    $errMsg = $d['message'] ?? $d['error'] ?? "HTTP {$code}";
    // 422 usually means the email already exists — treat as success
    if ($code === 422 && stripos($errMsg, 'exist') !== false) {
        return ['ok'=>true,'note'=>'already exists'];
    }
    return ['ok'=>false,'error'=>$errMsg];
}
