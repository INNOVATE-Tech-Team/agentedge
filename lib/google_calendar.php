<?php
// Service-account auth + Calendar API helpers. No Composer required — uses openssl + file_get_contents.

function gcal_access_token(string $key_file): ?string {
    static $cache = [];
    if (!empty($cache['token']) && $cache['expires'] > time() + 60) {
        return $cache['token'];
    }

    if (!file_exists($key_file)) return null;
    $key = json_decode(file_get_contents($key_file), true);
    if (!isset($key['private_key'], $key['client_email'])) return null;

    $now     = time();
    $header  = _gcal_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _gcal_b64u(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sig_input = "$header.$payload";
    $pkey = openssl_pkey_get_private($key['private_key']);
    if (!$pkey) return null;
    openssl_sign($sig_input, $sig, $pkey, 'SHA256');
    $jwt = $sig_input . '.' . _gcal_b64u($sig);

    $resp = @file_get_contents('https://oauth2.googleapis.com/token', false,
        stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            'ignore_errors' => true,
        ]])
    );
    if (!$resp) return null;
    $d = json_decode($resp, true);
    if (empty($d['access_token'])) return null;

    $cache = ['token' => $d['access_token'], 'expires' => $now + (int)($d['expires_in'] ?? 3600)];
    return $cache['token'];
}

function gcal_events(string $calendar_id, string $token, string $time_min, string $time_max): array {
    $url = 'https://www.googleapis.com/calendar/v3/calendars/'
        . urlencode($calendar_id) . '/events?'
        . http_build_query([
            'timeMin'      => $time_min,
            'timeMax'      => $time_max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 250,
        ]);

    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]));
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return $d['items'] ?? [];
}

function gcal_create_event(string $calendar_id, string $token, array $event): ?array {
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events';
    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => json_encode($event),
        'ignore_errors' => true,
    ]]));
    if (!$resp) return null;
    $d = json_decode($resp, true);
    return isset($d['id']) ? $d : null;
}

function gcal_update_event(string $calendar_id, string $token, string $event_id, array $patch): ?array {
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'PATCH',
        'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => json_encode($patch),
        'ignore_errors' => true,
    ]]));
    if (!$resp) return null;
    $d = json_decode($resp, true);
    return isset($d['id']) ? $d : null;
}

function gcal_get_event(string $calendar_id, string $token, string $event_id): ?array {
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]));
    if (!$resp) return null;
    $d = json_decode($resp, true);
    return isset($d['id']) ? $d : null;
}

// Adds $email as a real attendee on the Calendar event (merges into the existing
// attendee list, since the API's `attendees` field is a full replace on PATCH, not
// an append) and asks Google to send that agent a native invite (sendUpdates=all).
function gcal_add_attendee(string $calendar_id, string $token, string $event_id, string $email): ?array {
    $event = gcal_get_event($calendar_id, $token, $event_id);
    if (!$event) return null;
    $attendees = $event['attendees'] ?? [];
    foreach ($attendees as $a) {
        if (strcasecmp($a['email'] ?? '', $email) === 0) return $event; // already an attendee
    }
    $attendees[] = ['email' => $email];
    return gcal_update_event_sendupdates($calendar_id, $token, $event_id, ['attendees' => $attendees], 'all');
}

// Removes $email from the event's attendee list, if present.
function gcal_remove_attendee(string $calendar_id, string $token, string $event_id, string $email): ?array {
    $event = gcal_get_event($calendar_id, $token, $event_id);
    if (!$event) return null;
    $attendees = $event['attendees'] ?? [];
    $filtered = array_values(array_filter($attendees, fn($a) => strcasecmp($a['email'] ?? '', $email) !== 0));
    if (count($filtered) === count($attendees)) return $event; // wasn't an attendee
    return gcal_update_event_sendupdates($calendar_id, $token, $event_id, ['attendees' => $filtered], 'all');
}

// Same as gcal_update_event() but lets the caller control Google's notification
// behavior via ?sendUpdates= (the plain PATCH helper never sends attendee emails).
function gcal_update_event_sendupdates(string $calendar_id, string $token, string $event_id, array $patch, string $send_updates): ?array {
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id)
          . '?' . http_build_query(['sendUpdates' => $send_updates]);
    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'PATCH',
        'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => json_encode($patch),
        'ignore_errors' => true,
    ]]));
    if (!$resp) return null;
    $d = json_decode($resp, true);
    return isset($d['id']) ? $d : null;
}

function gcal_delete_event(string $calendar_id, string $token, string $event_id): bool {
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
    @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'DELETE',
        'header'        => "Authorization: Bearer $token\r\n",
        'ignore_errors' => true,
    ]]));
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^HTTP\/\S+ (\d+)/', $h, $m)) return (int)$m[1] === 204;
    }
    return false;
}

function _gcal_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
