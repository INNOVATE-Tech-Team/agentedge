<?php
// Shared Trestle/CoreLogic API helpers.
// Used by oh_mls_lookup.php and any other feature that queries the Trestle OData feed.

require_once __DIR__ . '/../local_db.php';

/**
 * Obtain a cached Trestle OAuth2 bearer token.
 * Token is cached in oh_prefs to avoid a round-trip on every request.
 */
function trestle_token(string $clientId, string $secret): string {
    if ($clientId === '' || $secret === '') return '';
    $now = time();
    $db  = local_db();

    // Return cached token if still valid (with 60-second buffer).
    $tokRow = $db->query("SELECT value FROM oh_prefs WHERE key='trestle_token'")->fetchColumn();
    $expRow = $db->query("SELECT value FROM oh_prefs WHERE key='trestle_token_expires'")->fetchColumn();
    if ($tokRow && $expRow && (int)$expRow > $now + 60) return $tokRow;

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 12,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content'       => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'grant_type'    => 'client_credentials',
            'scope'         => 'api',
        ]),
        'ignore_errors' => true,
    ]]);
    $raw   = @file_get_contents('https://api.cotality.com/trestle/oidc/connect/token', false, $ctx);
    $d     = $raw ? (json_decode($raw, true) ?? []) : [];
    $token = $d['access_token'] ?? '';
    if ($token) {
        $exp = $now + (int)($d['expires_in'] ?? 3600);
        $db->prepare("INSERT OR REPLACE INTO oh_prefs(key,value) VALUES('trestle_token',?)")->execute([$token]);
        $db->prepare("INSERT OR REPLACE INTO oh_prefs(key,value) VALUES('trestle_token_expires',?)")->execute([$exp]);
    }
    return $token;
}

/**
 * Extract the URL of the first photo from a Trestle Media array.
 * Prefers items where MediaCategory contains 'Photo'; falls back to first item.
 */
function trestle_first_photo(array $media): string {
    $fallback = '';
    foreach ($media as $m) {
        $url = $m['MediaURL'] ?? '';
        if ($url === '') continue;
        $cat = strtolower($m['MediaCategory'] ?? '');
        if (str_contains($cat, 'photo') || $cat === '') {
            return $url;
        }
        if ($fallback === '') $fallback = $url;
    }
    return $fallback;
}

/**
 * Normalize a Trestle PropertyType string to a concise display label.
 */
function trestle_normalize_type(string $type): string {
    $map = [
        'Residential'               => 'Single Family',
        'ResidentialIncome'         => 'Multi-Family',
        'Residential Income'        => 'Multi-Family',
        'ResidentialLease'          => 'Rental',
        'Residential Lease'         => 'Rental',
        'Land'                      => 'Land',
        'Commercial Sale'           => 'Commercial',
        'CommercialSale'            => 'Commercial',
        'Commercial Lease'          => 'Commercial Lease',
        'CommercialLease'           => 'Commercial Lease',
        'Business Opportunity'      => 'Business',
        'BusinessOpportunity'       => 'Business',
        'Farm'                      => 'Farm/Ranch',
    ];
    return $map[$type] ?? $type;
}
