<?php
// Google Business Profile audit helper library.
// Uses the Google Places API (Place Details, legacy endpoint — a plain API key
// is enough, unlike the Business Profile Management API which needs Google's
// approval process). All Places HTTP calls flow through here.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/notifications.php';

const GOOGLE_PLACES_DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';
const GOOGLE_PLACES_TEXTSEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

// Generic words that don't help disambiguate a name match against a business
// name — "real estate", brand words, and state abbreviations all show up
// constantly and would otherwise count as false "overlap".
const GOOGLE_PLACE_MATCH_STOPWORDS = ['realtor', 'real', 'estate', 'realty', 'team', 'group',
    'llc', 'inc', 'the', 'innovate', 'brg', 'with', 'at', 'of', 'and', 'a', 'agent',
    'properties', 'property', 'homes', 'home'];

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

/**
 * Build a tailored "please set this up" email for one agent, based on
 * exactly what's missing for them — used by backoffice_google_audit.php's
 * "Request Permission" bulk action. This is an internal staff->agent email
 * (not the client-facing review request), so it goes straight through
 * queue_email_to() with no separate approval queue.
 *
 * $status one of: 'needs_page' (no Place ID, no candidate), 'has_candidate'
 * (a discovered listing is waiting on their confirmation), 'not_opted_in'
 * (has a Place ID but hasn't checked the box).
 */
function google_permission_request_email(string $agentName, string $status): array {
    $firstName = trim(explode(' ', trim($agentName))[0] ?? '') ?: 'there';
    $profileUrl = 'https://agents.innovateonline.com/profile.php';

    $subject = 'Quick favor — help us send your clients a review request';

    $intro = "<p>Hi {$firstName},</p>"
           . "<p>We're rolling out a feature that automatically drafts a Google review request for your clients whenever one of your dotloop transactions closes — it saves you from having to remember to ask, and every email still gets a human review before it ever reaches a client.</p>";

    $ask = match ($status) {
        'has_candidate' => "<p>We think we already found your Google Business listing. Head to <a href=\"{$profileUrl}\">My Profile</a> and look for the box near the top — if it's really you, just click <strong>\"Yes, that's me\"</strong> and you're set.</p>",
        'not_opted_in'  => "<p>You already have a Google Place ID on file — you just need to check one box. Head to <a href=\"{$profileUrl}\">My Profile</a> and check \"Send automatic Google review requests to my clients when a transaction closes.\"</p>",
        default         => "<p>We couldn't find a Google Business Page for you yet. If you don't have one, it only takes a few minutes: create one at <a href=\"https://business.google.com/create\">business.google.com/create</a>, then paste the Place ID into the \"Google Business Profile\" section on <a href=\"{$profileUrl}\">My Profile</a> and check the opt-in box.</p>",
    };

    $body = notification_email_html($intro . $ask . sender_signature_html('', 'INNOVATE Real Estate'));
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Places API Text Search — used to *discover* a candidate listing for an
 * agent who hasn't self-entered a Place ID (unlike google_places_lookup(),
 * which looks up an already-known Place ID). Returns
 * ['ok'=>true, 'results'=>[...]] or ['ok'=>false, 'error'=>...].
 */
function google_places_text_search(string $query): array {
    $key = google_places_api_key();
    if ($key === '') return ['ok' => false, 'error' => 'No Google Places API key configured', 'results' => []];

    $url = GOOGLE_PLACES_TEXTSEARCH_URL . '?' . http_build_query(['query' => $query, 'key' => $key]);
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => 'Request to Google Places API failed', 'results' => []];

    $d = json_decode($raw, true);
    $status = $d['status'] ?? 'UNKNOWN_ERROR';
    if ($status !== 'OK') return ['ok' => false, 'error' => $status, 'results' => []];

    return ['ok' => true, 'results' => $d['results'] ?? []];
}

/** Lowercase word tokens, stripped of the generic stopwords above. */
function google_place_name_tokens(string $s): array {
    preg_match_all("/[a-z']+/", strtolower($s), $m);
    return array_values(array_diff($m[0], GOOGLE_PLACE_MATCH_STOPWORDS));
}

/**
 * Text-similarity score (0-100) between an agent's name and a candidate
 * business name, purely for ranking candidates from the same Text Search
 * response against each other — NOT a confidence measure on its own (a
 * same-named stranger elsewhere scores just as high). Real confidence comes
 * from combining this with a state match and a real name-token overlap, see
 * google_place_candidate_discover_all().
 */
function google_place_name_score(string $agentName, string $businessName): float {
    similar_text(strtolower($agentName), strtolower($businessName), $pct);
    return $pct;
}

/**
 * Find candidate Google Business listings for every active roster agent who
 * doesn't already have a google_place_id on file and doesn't already have a
 * decided (confirmed/dismissed) candidate. For each, runs a Text Search for
 * "<name> real estate <market center> <state>" and keeps the best result
 * only if: same state as the agent's roster row, score >= $minScore, and the
 * business name shares at least one real (non-stopword, 3+ char) token with
 * the agent's name — first name alone is not enough (too many collisions),
 * this needs at least one distinguishing token.
 *
 * Writes to google_place_candidates with status='pending' — never touches
 * agent_intake.google_place_id directly. The agent has to confirm it
 * themselves on their profile page (see api/profile.php) before it becomes
 * real. Re-running this is safe: existing pending rows get refreshed,
 * confirmed/dismissed rows are left alone (WHERE clause below).
 *
 * Returns ['ok'=>true, 'checked'=>N, 'candidates_found'=>N].
 */
function google_place_candidate_discover_all(int $minScore = 40): array {
    $db = local_db();
    $rows = $db->query(
        "SELECT r.agent_name AS name, r.email, r.market_center AS mc, r.state_code AS state
         FROM innovate_roster r
         LEFT JOIN agent_intake i ON LOWER(TRIM(i.email)) = LOWER(TRIM(r.email))
         LEFT JOIN google_place_candidates c ON LOWER(TRIM(c.email)) = LOWER(TRIM(r.email))
         WHERE r.active = 1
           AND TRIM(r.email) <> ''
           AND TRIM(COALESCE(i.google_place_id, '')) = ''
           AND (c.email IS NULL OR c.status = 'pending')"
    )->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $db->prepare(
        "INSERT INTO google_place_candidates
            (email, candidate_name, place_id, rating, review_count, formatted_addr, match_score, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))
         ON CONFLICT(email) DO UPDATE SET
            candidate_name = excluded.candidate_name, place_id = excluded.place_id,
            rating = excluded.rating, review_count = excluded.review_count,
            formatted_addr = excluded.formatted_addr, match_score = excluded.match_score,
            created_at = excluded.created_at
         WHERE google_place_candidates.status = 'pending'"
    );

    $checked = 0;
    $found   = 0;
    foreach ($rows as $r) {
        $checked++;
        $query = "{$r['name']} real estate {$r['mc']} {$r['state']}";
        $res = google_places_text_search($query);
        usleep(200000); // light throttle

        if (!$res['ok'] || empty($res['results'])) continue;

        $nameTokens = array_filter(google_place_name_tokens($r['name']), fn($t) => strlen($t) >= 3);
        $best = null; $bestScore = 0;
        foreach ($res['results'] as $place) {
            $score = google_place_name_score($r['name'], $place['name'] ?? '');
            if ($score > $bestScore) { $bestScore = $score; $best = $place; }
        }
        if (!$best || $bestScore < $minScore) continue;

        // State check: agent's roster state must appear in the result's formatted address.
        $addr = (string)($best['formatted_address'] ?? '');
        if ($r['state'] && stripos($addr, ", {$r['state']} ") === false && !preg_match('/,\s*' . preg_quote($r['state'], '/') . '\s*\d{5}/', $addr)) {
            continue;
        }

        // Real name-token overlap check.
        $bizTokens = array_filter(google_place_name_tokens($best['name'] ?? ''), fn($t) => strlen($t) >= 3);
        if (!array_intersect($nameTokens, $bizTokens)) continue;

        $upsert->execute([
            $r['email'], $best['name'] ?? '', $best['place_id'] ?? '',
            isset($best['rating']) ? (float)$best['rating'] : null,
            (int)($best['user_ratings_total'] ?? 0), $addr, (int)round($bestScore),
        ]);
        $found++;
    }

    return ['ok' => true, 'checked' => $checked, 'candidates_found' => $found];
}
