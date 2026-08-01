<?php
// Google Business Profile audit helper library.
// Uses the Google Places API (Place Details, legacy endpoint — a plain API key
// is enough, unlike the Business Profile Management API which needs Google's
// approval process). All Places HTTP calls flow through here.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

const GOOGLE_PLACES_DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

/** API key from config.php — cfg()['google_places_key']. Empty until Darren creates one in Google Cloud Console. */
function google_places_api_key(): string {
    $c = cfg();
    return $c['google_places_key'] ?? '';
}

/**
 * Look up one Place ID's current status via the Places API.
 * Returns ['ok'=>true, 'name'=>, 'business_status'=>, 'rating'=>, 'review_count'=>]
 * or ['ok'=>false, 'error'=>...] — never throws, so a bad/expired Place ID
 * just shows up as an error in the audit table instead of breaking the sync.
 */
function google_places_lookup(string $placeId): array {
    $key = google_places_api_key();
    if ($key === '') return ['ok' => false, 'error' => 'No Google Places API key configured'];
    if ($placeId === '') return ['ok' => false, 'error' => 'No Place ID on file'];

    $url = GOOGLE_PLACES_DETAILS_URL . '?' . http_build_query([
        'place_id' => $placeId,
        'fields'   => 'name,rating,user_ratings_total,business_status',
        'key'      => $key,
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 15,
        'header'        => "Accept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => 'Request to Google Places API failed'];

    $d = json_decode($raw, true);
    $status = $d['status'] ?? 'UNKNOWN_ERROR';
    if ($status !== 'OK') {
        return ['ok' => false, 'error' => $d['error_message'] ?? $status];
    }

    $result = $d['result'] ?? [];
    return [
        'ok'              => true,
        'name'            => (string)($result['name'] ?? ''),
        'business_status' => (string)($result['business_status'] ?? ''),
        'rating'          => isset($result['rating']) ? (float)$result['rating'] : null,
        'review_count'    => (int)($result['user_ratings_total'] ?? 0),
    ];
}

/**
 * Refresh google_business_audit for every agent with a Place ID on file
 * (agent_intake.google_place_id). Called nightly by cron/sync_google_audit.php
 * and on-demand from the "Refresh Now" button on backoffice_google_audit.php.
 * Returns ['ok'=>true, 'checked'=>N, 'errors'=>[...]].
 */
function google_business_sync_all(): array {
    $db = local_db();
    $rows = $db->query(
        "SELECT email, google_place_id FROM agent_intake WHERE TRIM(google_place_id) <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $db->prepare(
        "INSERT INTO google_business_audit
            (email, place_id, business_name, business_status, rating, review_count, last_checked_at, last_error)
         VALUES (?, ?, ?, ?, ?, ?, datetime('now'), ?)
         ON CONFLICT(email) DO UPDATE SET
            place_id        = excluded.place_id,
            business_name   = excluded.business_name,
            business_status = excluded.business_status,
            rating          = excluded.rating,
            review_count    = excluded.review_count,
            last_checked_at = excluded.last_checked_at,
            last_error      = excluded.last_error"
    );

    $checked = 0;
    $errors  = [];
    foreach ($rows as $row) {
        $placeId = trim($row['google_place_id']);
        $result  = google_places_lookup($placeId);

        if ($result['ok']) {
            $upsert->execute([
                $row['email'], $placeId, $result['name'], $result['business_status'],
                $result['rating'], $result['review_count'], '',
            ]);
        } else {
            $upsert->execute([$row['email'], $placeId, '', '', null, 0, $result['error']]);
            $errors[] = "{$row['email']}: {$result['error']}";
        }
        $checked++;
        usleep(150000); // light throttle — Places API has its own per-second quota
    }

    return ['ok' => true, 'checked' => $checked, 'errors' => $errors];
}

/** Build the direct "write a review" link for an agent's Google Business Profile. */
function google_review_link(string $placeId): string {
    return 'https://search.google.com/local/writereview?placeid=' . rawurlencode($placeId);
}
