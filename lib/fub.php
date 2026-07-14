<?php
// Follow Up Boss API — agent (team member) provisioning.
// API key configured in config.php as 'fub_api_key'.
// FUB requires every request to also identify the calling system via the
// 'fub_system_name' / 'fub_system_key' config values (register at
// https://apps.followupboss.com/system-registration).
// Docs: https://docs.followupboss.com/reference/users-create

function fub_system_headers(array $c): string {
    $sys    = $c['fub_system_name'] ?? '';
    $sysKey = $c['fub_system_key']  ?? '';
    return ($sys !== '' && $sysKey !== '') ? "X-System: {$sys}\r\nX-System-Key: {$sysKey}\r\n" : '';
}

function fub_create_user(string $name, string $email): array {
    $c      = cfg();
    $apiKey = $c['fub_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'FUB API key not configured'];

    $payload = json_encode(['name'=>$name,'email'=>$email,'role'=>'Agent']);
    $auth    = base64_encode($apiKey . ':');
    $ctx = stream_context_create(['http'=>[
        'method'        => 'POST',
        'timeout'       => 15,
        'header'        => "Authorization: Basic {$auth}\r\nContent-Type: application/json\r\nAccept: application/json\r\n" . fub_system_headers($c),
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

function fub_deactivate_user(string $email): array {
    $c      = cfg();
    $apiKey = $c['fub_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'FUB API key not configured'];

    $auth    = base64_encode($apiKey . ':');
    $headers = "Authorization: Basic {$auth}\r\nAccept: application/json\r\n" . fub_system_headers($c);

    // Step 1: find user by email
    $ctx = stream_context_create(['http'=>[
        'method'        => 'GET',
        'timeout'       => 15,
        'header'        => $headers,
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents('https://api.followupboss.com/v1/users?email=' . urlencode($email), false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    $d = $raw ? json_decode($raw, true) : null;

    if ($code !== 200 || empty($d['users'])) {
        return ['ok'=>true,'note'=>'not found in FUB — may already be removed'];
    }

    $userId = $d['users'][0]['id'] ?? null;
    if (!$userId) return ['ok'=>false,'error'=>'Could not determine FUB user ID'];

    // Step 2: deactivate via PUT with active=false
    $payload = json_encode(['active' => false]);
    $ctx2 = stream_context_create(['http'=>[
        'method'        => 'PUT',
        'timeout'       => 15,
        'header'        => $headers . "Content-Type: application/json\r\n",
        'content'       => $payload,
        'ignore_errors' => true,
    ]]);
    $raw2  = @file_get_contents("https://api.followupboss.com/v1/users/{$userId}", false, $ctx2);
    $code2 = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code2 = (int)$m[1]);
    }

    if ($code2 >= 200 && $code2 < 300) {
        return ['ok'=>true];
    }
    $d2     = $raw2 ? json_decode($raw2, true) : null;
    $errMsg = $d2['message'] ?? $d2['error'] ?? "HTTP {$code2}";
    return ['ok'=>false,'error'=>$errMsg];
}
