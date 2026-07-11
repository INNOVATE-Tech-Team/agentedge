<?php
// Free, no-API-key geocoding via the US Census Bureau geocoder, plus a
// great-circle distance helper. Used to locate Market Centers and
// recruiting prospects so distance-to-nearest-MC can be computed.

/**
 * Geocode a US address to [lat, lng]. Returns null on no match or any
 * network/parse failure — callers should treat that as "not located yet",
 * not an error.
 */
function geocode_address(string $line1, string $city, string $state, string $zip): ?array {
    $oneLine = trim(implode(', ', array_filter([$line1, $city, $state, $zip])));
    if ($oneLine === '') return null;

    $url = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress'
         . '?address=' . urlencode($oneLine)
         . '&benchmark=Public_AR_Current&format=json';

    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data    = json_decode($raw, true);
    $matches = $data['result']['addressMatches'] ?? [];
    if (!$matches) return null;

    $coords = $matches[0]['coordinates'] ?? null;
    if (!$coords || !isset($coords['x'], $coords['y'])) return null;

    return ['lat' => (float)$coords['y'], 'lng' => (float)$coords['x']];
}

/** Great-circle distance between two points, in miles. */
function haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusMi = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusMi * $c;
}
